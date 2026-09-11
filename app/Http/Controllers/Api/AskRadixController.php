<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RadixAnswerResource;
use App\Http\Resources\RadixQuestionResource;
use App\Models\RadixAnswer;
use App\Models\RadixQuestion;
use App\Models\RadixVolunteer;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Ask Radix — Learn & Share, Phase 2.
 *
 * The point is that the asker does not need to know who can help. Tags route the
 * question, and relevant people can either answer in writing or simply volunteer
 * to talk — which is often the more useful of the two.
 */
class AskRadixController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:open,answered,closed'],
            'tag' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'for_me' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        $query = RadixQuestion::query()
            ->with(['user', 'tags'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('title', 'like', '%'.$term.'%')
                    ->orWhere('body', 'like', '%'.$term.'%')
            ))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['tag'] ?? null, fn ($q, $slug) => $q->whereHas('tags', fn ($t) => $t->where('slug', $slug)))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id));

        // "Questions I could probably help with" — matched against the things
        // this person says they can talk about or help with.
        $myTagIds = $this->myExpertiseTagIds($user);

        if ($request->boolean('for_me')) {
            $query->where('user_id', '!=', $user->id)
                ->where('status', 'open')
                ->whereHas('tags', fn ($t) => $t->whereIn('tags.id', $myTagIds));
        }

        $questions = $query->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $this->attachViewerState($questions, $user->id, $myTagIds);

        return RadixQuestionResource::collection($questions);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['nullable', 'string', 'max:4000'],
            'tags' => ['nullable', 'array', 'max:6'],
            'tags.*' => ['string', 'max:60'],
        ]);

        $question = $request->user()->radixQuestions()->create([
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
        ]);

        $this->syncTags($question, $data['tags'] ?? []);

        return response()->json([
            'data' => new RadixQuestionResource($question->load(['user', 'tags'])),
        ], 201);
    }

    public function show(Request $request, RadixQuestion $question): JsonResponse
    {
        $question->load(['user', 'tags', 'answers.user', 'volunteers.user']);
        $question->i_volunteered = $question->volunteers->contains('user_id', $request->user()->id);

        return response()->json(['data' => new RadixQuestionResource($question)]);
    }

    public function update(Request $request, RadixQuestion $question): JsonResponse
    {
        $this->assertAsker($request, $question);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'body' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'status' => ['sometimes', 'in:open,answered,closed'],
            'tags' => ['sometimes', 'array', 'max:6'],
            'tags.*' => ['string', 'max:60'],
        ]);

        $question->update(collect($data)->except('tags')->all());

        if (array_key_exists('tags', $data)) {
            $this->syncTags($question, $data['tags']);
        }

        return response()->json([
            'data' => new RadixQuestionResource($question->fresh()->load(['user', 'tags'])),
        ]);
    }

    public function destroy(Request $request, RadixQuestion $question): JsonResponse
    {
        $this->assertAsker($request, $question);
        $question->delete();

        return response()->json(['message' => 'Question removed.']);
    }

    public function answer(Request $request, RadixQuestion $question): JsonResponse
    {
        if ($question->status === 'closed') {
            return response()->json(['message' => 'This question is closed.'], 422);
        }

        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $answer = $question->answers()->create($data + ['user_id' => $request->user()->id]);
        $question->syncCounts();

        return response()->json([
            'data' => new RadixAnswerResource($answer->load('user')),
        ], 201);
    }

    /** "I have done this — come talk to me." */
    public function volunteer(Request $request, RadixQuestion $question): JsonResponse
    {
        if ($question->user_id === $request->user()->id) {
            return response()->json(['message' => 'You asked this one.'], 422);
        }

        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        $question->volunteers()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['note' => $data['note'] ?? null],
        );
        $question->syncCounts();

        return $this->show($request, $question->fresh());
    }

    public function withdrawVolunteer(Request $request, RadixQuestion $question): JsonResponse
    {
        $question->volunteers()->where('user_id', $request->user()->id)->delete();
        $question->syncCounts();

        return $this->show($request, $question->fresh());
    }

    /** The asker marks the answer that actually helped. */
    public function acceptAnswer(Request $request, RadixAnswer $answer): JsonResponse
    {
        $question = $answer->question;

        // Deliberately no admin bypass: "this is the answer that helped me" is
        // the asker's call, not a moderation action.
        if ($question->user_id !== $request->user()->id) {
            throw new AccessDeniedHttpException('Only the person who asked can accept an answer.');
        }

        $question->answers()->update(['is_accepted' => false]);
        $answer->update(['is_accepted' => true]);

        $question->update([
            'accepted_answer_id' => $answer->id,
            'status' => 'answered',
            'resolved_at' => now(),
        ]);

        return $this->show($request, $question->fresh());
    }

    public function deleteAnswer(Request $request, RadixAnswer $answer): JsonResponse
    {
        $user = $request->user();

        if ($answer->user_id !== $user->id && $answer->question->user_id !== $user->id && ! $user->isAdmin()) {
            throw new AccessDeniedHttpException('You cannot remove this answer.');
        }

        $question = $answer->question;
        $answer->delete();
        $question->syncCounts();

        return response()->json(['message' => 'Answer removed.']);
    }

    protected function syncTags(RadixQuestion $question, array $names): void
    {
        $ids = collect($names)
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique()
            ->map(fn (string $name) => Tag::findOrCreateByName($name, 'skill')->id)
            ->all();

        $question->tags()->sync($ids);
    }

    protected function myExpertiseTagIds($user): array
    {
        return $user->tags()
            ->wherePivotIn('kind', ['can_talk_about', 'can_help_with'])
            ->pluck('tags.id')
            ->all();
    }

    protected function attachViewerState($questions, int $userId, array $myTagIds): void
    {
        $volunteered = RadixVolunteer::query()
            ->where('user_id', $userId)
            ->whereIn('radix_question_id', $questions->pluck('id'))
            ->pluck('radix_question_id')
            ->all();

        $questions->each(function (RadixQuestion $q) use ($volunteered, $myTagIds) {
            $q->i_volunteered = in_array($q->id, $volunteered, true);
            $q->matches_my_profile = $q->relationLoaded('tags')
                && $q->tags->pluck('id')->intersect($myTagIds)->isNotEmpty();
        });
    }

    protected function assertAsker(Request $request, RadixQuestion $question): void
    {
        if ($question->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new AccessDeniedHttpException('This question is not yours.');
        }
    }
}
