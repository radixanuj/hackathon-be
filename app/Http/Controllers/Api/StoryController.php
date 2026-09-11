<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AmaResource;
use App\Http\Resources\StoryResource;
use App\Models\Ama;
use App\Models\Story;
use App\Models\StoryReaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Beyond-work Stories. Not recognition or awards - the point is
 * "I didn't know this about that person."
 */
class StoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', Rule::in(Story::CATEGORIES)],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $stories = Story::query()
            ->with(['user', 'ama'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('title', 'like', '%'.$term.'%')
                    ->orWhere('body', 'like', '%'.$term.'%')
            ))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $this->attachReactionState($stories, $request->user()->id);

        return StoryResource::collection($stories);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:6000'],
            'category' => ['required', Rule::in(Story::CATEGORIES)],
            'media_url' => ['nullable', 'url', 'max:500'],
        ]);

        $story = $request->user()->stories()->create($data);

        return response()->json(['data' => new StoryResource($story->load('user'))], 201);
    }

    public function show(Request $request, Story $story): JsonResponse
    {
        $story->load(['user', 'ama']);
        $story->my_reaction = $story->reactions()
            ->where('user_id', $request->user()->id)
            ->value('reaction');

        return response()->json(['data' => new StoryResource($story)]);
    }

    public function update(Request $request, Story $story): JsonResponse
    {
        $this->assertOwner($request, $story);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'body' => ['sometimes', 'string', 'max:6000'],
            'category' => ['sometimes', Rule::in(Story::CATEGORIES)],
            'media_url' => ['sometimes', 'nullable', 'url', 'max:500'],
        ]);

        $story->update($data);

        return response()->json(['data' => new StoryResource($story->fresh()->load(['user', 'ama']))]);
    }

    public function destroy(Request $request, Story $story): JsonResponse
    {
        $this->assertOwner($request, $story);
        $story->delete();

        return response()->json(['message' => 'Story removed.']);
    }

    public function react(Request $request, Story $story): JsonResponse
    {
        $data = $request->validate([
            'reaction' => ['required', Rule::in(StoryReaction::REACTIONS)],
        ]);

        $story->reactions()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['reaction' => $data['reaction']],
        );
        $story->syncReactionsCount();

        return $this->show($request, $story->fresh());
    }

    public function removeReaction(Request $request, Story $story): JsonResponse
    {
        $story->reactions()->where('user_id', $request->user()->id)->delete();
        $story->syncReactionsCount();

        return $this->show($request, $story->fresh());
    }

    /** A Story can naturally become an AMA. */
    public function convertToAma(Request $request, Story $story): JsonResponse
    {
        $this->assertOwner($request, $story);

        if ($story->ama_id) {
            return response()->json(['message' => 'This story already has an AMA.'], 422);
        }

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'format' => ['nullable', Rule::in(Ama::FORMATS)],
            'scheduled_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:180'],
        ]);

        $ama = Ama::create([
            'host_id' => $story->user_id,
            'title' => $data['title'] ?? 'AMA: '.$story->title,
            'description' => $story->body,
            'format' => $data['format'] ?? 'async',
            'status' => 'open',
            'opens_at' => now(),
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'location' => $data['location'] ?? null,
            'story_id' => $story->id,
        ]);

        $story->update(['ama_id' => $ama->id]);

        return response()->json(['data' => new AmaResource($ama->load('host'))], 201);
    }

    protected function assertOwner(Request $request, Story $story): void
    {
        if ($story->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new AccessDeniedHttpException('This story is not yours.');
        }
    }

    protected function attachReactionState($stories, int $userId): void
    {
        $mine = StoryReaction::query()
            ->where('user_id', $userId)
            ->whereIn('story_id', $stories->pluck('id'))
            ->pluck('reaction', 'story_id');

        $stories->each(fn (Story $s) => $s->my_reaction = $mine[$s->id] ?? null);
    }
}
