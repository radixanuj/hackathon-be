<?php

namespace App\Services;

use App\Models\MeetupPair;
use App\Models\MeetupRound;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pairs Blind Meetup signups.
 *
 * The one hard rule for the initial format is the tenure split: 6+ years at
 * Radix pairs with under 6 years. Different team and different location are
 * preferences on top of that, not requirements, so a round still matches
 * everyone it can even when the pool is small.
 */
class BlindMeetupMatcher
{
    public function __construct(protected Notifier $notifier) {}

    public function match(MeetupRound $round): Collection
    {
        $signups = $round->signups()
            ->where('status', '!=', 'withdrawn')
            ->with('user')
            ->get();

        $seniors = $signups->filter(fn ($s) => $s->user->tenureBand() === 'senior')->values();
        $juniors = $signups->filter(fn ($s) => $s->user->tenureBand() === 'junior')->values();

        $pairs = collect();
        $matchedSignupIds = [];

        $result = DB::transaction(function () use ($round, $seniors, $juniors, $pairs, $matchedSignupIds) {
            $round->pairs()->delete();

            $availableJuniors = $juniors->all();

            foreach ($seniors as $senior) {
                if ($availableJuniors === []) {
                    break;
                }

                $bestIndex = $this->bestPartnerIndex($senior->user, $availableJuniors);
                $junior = $availableJuniors[$bestIndex];
                unset($availableJuniors[$bestIndex]);
                $availableJuniors = array_values($availableJuniors);

                $pairs->push(MeetupPair::create([
                    'meetup_round_id' => $round->id,
                    'user_one_id' => $senior->user_id,
                    'user_two_id' => $junior->user_id,
                    'match_reason' => $this->reason($senior->user, $junior->user),
                ]));

                $matchedSignupIds[] = $senior->id;
                $matchedSignupIds[] = $junior->id;
            }

            $round->signups()->whereIn('id', $matchedSignupIds)->update(['status' => 'matched']);
            $round->signups()
                ->whereNotIn('id', $matchedSignupIds)
                ->where('status', '!=', 'withdrawn')
                ->update(['status' => 'unmatched']);

            $round->update(['status' => 'matched']);

            return $pairs;
        });

        $this->announce($round, $result);

        return $result;
    }

    /**
     * Tell everyone where they stand once a round is matched.
     *
     * The pair notification withholds the name on purpose — the reveal belongs
     * to the app, so all this carries is why the two of them were put together.
     */
    protected function announce(MeetupRound $round, Collection $pairs): void
    {
        foreach ($pairs as $pair) {
            $this->notifier->sendMany([$pair->user_one_id, $pair->user_two_id], 'meetup.matched', [
                'title' => 'Your Blind Meetup match is ready',
                'body' => $pair->match_reason,
                'subject' => $pair,
                'action_url' => '/connect?tab=meetup',
                'data' => ['round' => $round->title, 'meetup_date' => $round->meetup_date?->toDateString()],
            ]);
        }

        $unmatched = $round->signups()->where('status', 'unmatched')->pluck('user_id');

        $this->notifier->sendMany($unmatched, 'meetup.unmatched', [
            'title' => 'No match this round',
            'body' => 'The pool did not divide evenly — you are first in line for '.$round->title.'.',
            'subject' => $round,
            'action_url' => '/connect?tab=meetup',
        ]);
    }

    /** Prefer a partner from a different team, then a different location. */
    protected function bestPartnerIndex(User $person, array $candidates): int
    {
        $bestIndex = 0;
        $bestScore = PHP_INT_MIN;

        foreach ($candidates as $index => $signup) {
            $other = $signup->user;
            $score = random_int(0, 3);

            if ($other->team && $other->team !== $person->team) {
                $score += 10;
            }

            if ($other->location && $other->location !== $person->location) {
                $score += 6;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    protected function reason(User $a, User $b): string
    {
        $bits = [];

        $bits[] = $a->tenureYears().' years at Radix meets '.$b->tenureYears().' years';

        if ($a->team && $b->team && $a->team !== $b->team) {
            $bits[] = $a->team.' meets '.$b->team;
        }

        if ($a->location && $b->location && $a->location !== $b->location) {
            $bits[] = $a->location.' meets '.$b->location;
        }

        return implode(', ', $bits).'.';
    }

    /** The meetup lands on the last Friday of the given month. */
    public static function lastFridayOf(int $year, int $month): Carbon
    {
        $date = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();

        return $date->isFriday() ? $date : $date->previous(CarbonInterface::FRIDAY);
    }
}
