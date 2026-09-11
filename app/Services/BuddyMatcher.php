<?php

namespace App\Services;

use App\Models\BuddyPairing;
use App\Models\BuddySignup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cross-location Buddy - Phase 2.
 *
 * Unlike the Blind Meetups there is no monthly round: you opt in and get paired
 * as soon as someone in a different office is also waiting. A different location
 * is the whole point of the feature, so it is a hard requirement - if nobody
 * qualifies you stay in the pool rather than being paired with a neighbour.
 */
class BuddyMatcher
{
    public function __construct(protected Notifier $notifier) {}

    /** Pair this signup with a waiting person elsewhere, or leave it waiting. */
    public function matchFor(User $user): ?BuddyPairing
    {
        $pairing = DB::transaction(function () use ($user) {
            $signup = BuddySignup::where('user_id', $user->id)->where('status', 'waiting')->first();

            if (! $signup) {
                return null;
            }

            $partnerSignup = $this->findPartner($user);

            if (! $partnerSignup) {
                return null;
            }

            $partner = $partnerSignup->user;

            $pairing = BuddyPairing::create([
                'user_one_id' => $user->id,
                'user_two_id' => $partner->id,
                'match_reason' => $this->reason($user, $partner),
                'status' => 'active',
                'started_at' => now(),
            ]);

            BuddySignup::whereIn('id', [$signup->id, $partnerSignup->id])
                ->update(['status' => 'paired']);

            return $pairing;
        });

        // The person who opted in sees the result in the response; the one who
        // has been sitting in the pool only finds out through their inbox.
        if ($pairing) {
            $partner = $pairing->partnerFor($user->id);

            $this->notifier->send($partner, 'buddy.matched', [
                'title' => 'You have a buddy in another office',
                'body' => $user->name.' just joined the pool. '.$pairing->match_reason,
                'subject' => $pairing,
                'action_url' => '/connect?tab=buddy',
            ]);
        }

        return $pairing;
    }

    protected function findPartner(User $user): ?BuddySignup
    {
        $alreadyPaired = BuddyPairing::forUser($user->id)->get()
            ->map(fn (BuddyPairing $p) => $p->partnerFor($user->id)?->id)
            ->filter()
            ->all();

        $candidates = BuddySignup::query()
            ->where('status', 'waiting')
            ->where('user_id', '!=', $user->id)
            ->whereNotIn('user_id', $alreadyPaired)
            ->with('user')
            ->get()
            // A buddy in your own office defeats the purpose.
            ->filter(fn (BuddySignup $s) => $s->user->location
                && $s->user->location !== $user->location);

        if ($candidates->isEmpty()) {
            return null;
        }

        // Among valid partners, prefer a different team too, then longest waiting.
        return $candidates
            ->sortBy(fn (BuddySignup $s) => [
                $s->user->team === $user->team ? 1 : 0,
                $s->created_at?->timestamp ?? 0,
            ])
            ->first();
    }

    protected function reason(User $a, User $b): string
    {
        $bits = [$a->location.' meets '.$b->location];

        if ($a->team && $b->team && $a->team !== $b->team) {
            $bits[] = $a->team.' meets '.$b->team;
        }

        return implode(', ', $bits).'.';
    }
}
