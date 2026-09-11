<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetupPairResource;
use App\Http\Resources\MeetupRoundResource;
use App\Http\Resources\MeetupSignupResource;
use App\Models\MeetupPair;
use App\Models\MeetupRound;
use App\Services\BlindMeetupMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class MeetupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $rounds = MeetupRound::query()
            ->withCount(['signups', 'pairs'])
            ->orderByDesc('meetup_date')
            ->paginate($request->integer('per_page', 12));

        return MeetupRoundResource::collection($rounds);
    }

    /** The round the sign-up card on the home screen should show. */
    public function current(Request $request): JsonResponse
    {
        $round = MeetupRound::query()
            ->whereIn('status', ['open', 'matched'])
            ->where('meetup_date', '>=', now()->subDay()->toDateString())
            ->orderBy('meetup_date')
            ->withCount(['signups', 'pairs'])
            ->first();

        if (! $round) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => new MeetupRoundResource($this->withViewerState($round, $request))]);
    }

    public function show(Request $request, MeetupRound $round): JsonResponse
    {
        $round->loadCount(['signups', 'pairs']);

        return response()->json(['data' => new MeetupRoundResource($this->withViewerState($round, $request))]);
    }

    public function signUp(Request $request, MeetupRound $round): JsonResponse
    {
        if (! $round->isAcceptingSignups()) {
            return response()->json(['message' => 'Sign-ups for this round are closed.'], 422);
        }

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $signup = $round->signups()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['status' => 'signed_up', 'note' => $data['note'] ?? null],
        );

        return response()->json([
            'data' => new MeetupSignupResource($signup->load('user')),
        ], 201);
    }

    public function withdraw(Request $request, MeetupRound $round): JsonResponse
    {
        $signup = $round->signups()->where('user_id', $request->user()->id)->first();

        if (! $signup) {
            return response()->json(['message' => 'You are not signed up for this round.'], 404);
        }

        $signup->update(['status' => 'withdrawn']);

        return response()->json(['message' => 'Withdrawn from this round.']);
    }

    /** Every pair the signed-in user has ever been part of. */
    public function myPairs(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->id;

        $pairs = MeetupPair::query()
            ->where(fn ($q) => $q->where('user_one_id', $userId)->orWhere('user_two_id', $userId))
            ->with(['round', 'userOne', 'userTwo'])
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 12));

        return MeetupPairResource::collection($pairs);
    }

    public function updatePair(Request $request, MeetupPair $pair): JsonResponse
    {
        if (! $pair->includes($request->user()->id)) {
            throw new AccessDeniedHttpException('You are not part of this pairing.');
        }

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['proposed', 'scheduled', 'completed', 'missed'])],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $pair->update($data);

        return response()->json([
            'data' => new MeetupPairResource($pair->fresh()->load(['round', 'userOne', 'userTwo'])),
        ]);
    }

    // --- Admin ---------------------------------------------------------------

    public function store(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:140'],
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/', Rule::unique('meetup_rounds', 'period')],
            'signups_open_at' => ['required', 'date'],
            'signups_close_at' => ['required', 'date', 'after:signups_open_at'],
            'meetup_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['draft', 'open'])],
        ]);

        // Default to the last Friday of the round's month.
        if (empty($data['meetup_date'])) {
            [$year, $month] = array_map('intval', explode('-', $data['period']));
            $data['meetup_date'] = BlindMeetupMatcher::lastFridayOf($year, $month)->toDateString();
        }

        $round = MeetupRound::create($data + ['status' => $data['status'] ?? 'open']);

        return response()->json(['data' => new MeetupRoundResource($round)], 201);
    }

    public function runMatching(Request $request, MeetupRound $round, BlindMeetupMatcher $matcher): JsonResponse
    {
        $this->assertAdmin($request);

        $pairs = $matcher->match($round);

        return response()->json([
            'message' => $pairs->count().' pair(s) created.',
            'data' => MeetupPairResource::collection(
                $round->pairs()->with(['userOne', 'userTwo'])->get()
            ),
        ]);
    }

    protected function assertAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            throw new AccessDeniedHttpException('Admins only.');
        }
    }

    /** Attach the viewer's own signup and pairing to a round. */
    protected function withViewerState(MeetupRound $round, Request $request): MeetupRound
    {
        $userId = $request->user()->id;

        $round->setRelation('mySignup', $round->signups()->where('user_id', $userId)->first());
        $round->setRelation('myPair', $round->pairs()
            ->where(fn ($q) => $q->where('user_one_id', $userId)->orWhere('user_two_id', $userId))
            ->with(['userOne', 'userTwo'])
            ->first());

        return $round;
    }
}
