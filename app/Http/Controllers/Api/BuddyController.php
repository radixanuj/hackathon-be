<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BuddyPairingResource;
use App\Http\Resources\BuddySignupResource;
use App\Models\BuddyPairing;
use App\Models\BuddySignup;
use App\Services\BuddyMatcher;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Cross-location Buddy — Connect, Phase 2.
 *
 * Opting in tries to pair you straight away; if nobody in another office is
 * waiting you sit in the pool until someone is.
 */
class BuddyController extends Controller
{
    public function __construct(protected Notifier $notifier) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $signup = $user->buddySignup;
        $pairing = $user->activeBuddyPairing();

        return response()->json([
            'data' => [
                'signup' => $signup ? new BuddySignupResource($signup) : null,
                'pairing' => $pairing
                    ? new BuddyPairingResource($pairing->load(['userOne', 'userTwo']))
                    : null,
            ],
        ]);
    }

    public function optIn(Request $request, BuddyMatcher $matcher): JsonResponse
    {
        $user = $request->user();

        if (! $user->location) {
            return response()->json([
                'message' => 'Add your location to your profile first — this pairs you across offices.',
            ], 422);
        }

        if ($user->activeBuddyPairing()) {
            return response()->json(['message' => 'You already have an active buddy.'], 422);
        }

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        BuddySignup::updateOrCreate(
            ['user_id' => $user->id],
            ['status' => 'waiting', 'note' => $data['note'] ?? null],
        );

        $pairing = $matcher->matchFor($user);

        return response()->json([
            'message' => $pairing
                ? 'Matched with a buddy in another office.'
                : 'You are in the pool — we will pair you as soon as someone in another office joins.',
            'data' => [
                'signup' => new BuddySignupResource($user->fresh()->buddySignup),
                'pairing' => $pairing
                    ? new BuddyPairingResource($pairing->load(['userOne', 'userTwo']))
                    : null,
            ],
        ], 201);
    }

    public function optOut(Request $request): JsonResponse
    {
        $signup = $request->user()->buddySignup;

        if (! $signup) {
            return response()->json(['message' => 'You are not in the buddy pool.'], 404);
        }

        $signup->update(['status' => 'withdrawn']);

        return response()->json(['message' => 'Left the buddy pool.']);
    }

    public function history(Request $request): JsonResponse
    {
        $pairings = BuddyPairing::forUser($request->user()->id)
            ->with(['userOne', 'userTwo'])
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => BuddyPairingResource::collection($pairings)]);
    }

    /** Either buddy can wind the pairing up, which puts neither back in the pool. */
    public function end(Request $request, BuddyPairing $pairing): JsonResponse
    {
        if (! $pairing->includes($request->user()->id)) {
            throw new AccessDeniedHttpException('This pairing is not yours.');
        }

        $pairing->update(['status' => 'ended', 'ended_at' => now()]);

        $this->notifier->send($pairing->partnerFor($request->user()->id), 'buddy.ended', [
            'actor' => $request->user(),
            'title' => $request->user()->name.' wrapped up your buddy pairing',
            'body' => 'You can opt back into the pool whenever you like.',
            'subject' => $pairing,
            'action_url' => '/connect?tab=buddy',
        ]);

        return response()->json([
            'data' => new BuddyPairingResource($pairing->fresh()->load(['userOne', 'userTwo'])),
        ]);
    }
}
