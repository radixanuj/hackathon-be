<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChallengeParticipantResource;
use App\Http\Resources\ChallengeResource;
use App\Models\Challenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Challenges — Communities, Phase 2.
 *
 * A challenge counts one thing; `unit` says what (km, books, photos, sessions).
 * Participants log entries and the leaderboard falls out of the totals.
 */
class ChallengeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', Rule::in(Challenge::CATEGORIES)],
            'group_id' => ['nullable', 'integer', 'exists:interest_groups,id'],
            'scope' => ['nullable', 'in:active,upcoming,past,mine,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $userId = $request->user()->id;
        $scope = $filters['scope'] ?? 'active';

        $query = Challenge::query()
            ->with(['creator', 'group'])
            ->where('status', '!=', 'draft')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('title', 'like', '%'.$term.'%')
                    ->orWhere('description', 'like', '%'.$term.'%')
            ))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($filters['group_id'] ?? null, fn ($q, $id) => $q->where('interest_group_id', $id));

        match ($scope) {
            'active' => $query->active()->orderBy('ends_on'),
            'upcoming' => $query->whereDate('starts_on', '>', now())->orderBy('starts_on'),
            'past' => $query->whereDate('ends_on', '<', now())->orderByDesc('ends_on'),
            'mine' => $query->whereHas('participants', fn ($p) => $p->where('user_id', $userId))
                ->orderByDesc('ends_on'),
            default => $query->orderByDesc('starts_on'),
        };

        $challenges = $query->paginate($filters['per_page'] ?? 20)->withQueryString();
        $challenges->each(fn (Challenge $c) => $this->attachMyParticipation($c, $userId));

        return ChallengeResource::collection($challenges);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::in(Challenge::CATEGORIES)],
            'unit' => ['required', 'string', 'max:24'],
            'goal_value' => ['nullable', 'integer', 'min:1'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'interest_group_id' => ['nullable', 'integer', 'exists:interest_groups,id'],
            'status' => ['nullable', 'in:draft,open'],
        ]);

        $challenge = Challenge::create($data + [
            'slug' => $this->uniqueSlug($data['title']),
            'created_by' => $request->user()->id,
        ]);

        // Starting a challenge means you are in it.
        $challenge->participants()->create(['user_id' => $request->user()->id]);
        $challenge->syncParticipantsCount();

        return response()->json([
            'data' => new ChallengeResource(
                $this->attachMyParticipation($challenge->fresh()->load(['creator', 'group']), $request->user()->id)
            ),
        ], 201);
    }

    public function show(Request $request, Challenge $challenge): JsonResponse
    {
        $challenge->load(['creator', 'group']);
        $challenge->setRelation(
            'participants',
            $this->rankedParticipants($challenge)
        );
        $this->attachMyParticipation($challenge, $request->user()->id);

        return response()->json(['data' => new ChallengeResource($challenge)]);
    }

    public function update(Request $request, Challenge $challenge): JsonResponse
    {
        $this->assertOwner($request, $challenge);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:140'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['sometimes', Rule::in(Challenge::CATEGORIES)],
            'unit' => ['sometimes', 'string', 'max:24'],
            'goal_value' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'starts_on' => ['sometimes', 'date'],
            'ends_on' => ['sometimes', 'date'],
            'status' => ['sometimes', 'in:draft,open,completed,cancelled'],
        ]);

        $challenge->update($data);

        return response()->json([
            'data' => new ChallengeResource($challenge->fresh()->load(['creator', 'group'])),
        ]);
    }

    public function destroy(Request $request, Challenge $challenge): JsonResponse
    {
        $this->assertOwner($request, $challenge);
        $challenge->delete();

        return response()->json(['message' => 'Challenge removed.']);
    }

    public function join(Request $request, Challenge $challenge): JsonResponse
    {
        if (in_array($challenge->status, ['cancelled', 'completed'], true)) {
            return response()->json(['message' => 'This challenge is closed.'], 422);
        }

        $challenge->participants()->firstOrCreate(['user_id' => $request->user()->id]);
        $challenge->syncParticipantsCount();

        return $this->show($request, $challenge->fresh());
    }

    public function leave(Request $request, Challenge $challenge): JsonResponse
    {
        $challenge->participants()->where('user_id', $request->user()->id)->delete();
        $challenge->syncParticipantsCount();

        return $this->show($request, $challenge->fresh());
    }

    /** Log progress — kilometres run, books read, photos taken. */
    public function log(Request $request, Challenge $challenge): JsonResponse
    {
        $participant = $challenge->participants()->where('user_id', $request->user()->id)->first();

        if (! $participant) {
            return response()->json(['message' => 'Join the challenge before logging progress.'], 422);
        }

        if (! $challenge->isRunning()) {
            return response()->json(['message' => 'This challenge is not running right now.'], 422);
        }

        $data = $request->validate([
            'value' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:180'],
            'logged_on' => ['nullable', 'date'],
        ]);

        $participant->logs()->create($data + ['logged_on' => $data['logged_on'] ?? now()->toDateString()]);
        $participant->syncTotal();

        return response()->json([
            'data' => new ChallengeParticipantResource($participant->fresh()->load(['user', 'logs'])),
        ], 201);
    }

    public function leaderboard(Request $request, Challenge $challenge): AnonymousResourceCollection
    {
        return ChallengeParticipantResource::collection($this->rankedParticipants($challenge));
    }

    protected function rankedParticipants(Challenge $challenge)
    {
        return $challenge->participants()
            ->with('user')
            ->orderByDesc('total_value')
            ->orderBy('id')
            ->get()
            ->values()
            ->each(fn ($participant, $index) => $participant->rank = $index + 1);
    }

    protected function attachMyParticipation(Challenge $challenge, int $userId): Challenge
    {
        $mine = $challenge->participants()->where('user_id', $userId)->with('logs')->first();
        $challenge->setRelation('myParticipation', $mine);

        return $challenge;
    }

    protected function assertOwner(Request $request, Challenge $challenge): void
    {
        if ($challenge->created_by !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new AccessDeniedHttpException('Only the person who started this challenge can manage it.');
        }
    }

    protected function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'challenge';
        $slug = $base;
        $suffix = 2;

        while (Challenge::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
