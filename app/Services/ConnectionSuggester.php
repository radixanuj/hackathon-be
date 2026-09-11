<?php

namespace App\Services;

use App\Models\BuddyPairing;
use App\Models\CoffeeInvite;
use App\Models\CoffeeInviteJoin;
use App\Models\MeetupPair;
use App\Models\OfficeHourBooking;
use App\Models\OfficeHourSlot;
use App\Models\QuestTarget;
use App\Models\SessionRequest;
use App\Models\SuggestionDismissal;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * "Who Should I Meet?" - Phase 2.
 *
 * Ranks colleagues on the three things the pillar asks for: shared interests,
 * complementary knowledge, and a lack of previous interaction. The last one is
 * the point of the feature, so someone you have already been paired with drops
 * a long way down rather than being quietly hidden - when the network is small
 * everyone eventually has met everyone, and an empty screen helps nobody.
 */
class ConnectionSuggester
{
    public const DEFAULT_COUNT = 5;

    /** @return Collection<int, array{user: User, score: int, reasons: array<int, string>, previously_connected: bool}> */
    public function suggestFor(User $user, int $count = self::DEFAULT_COUNT): Collection
    {
        $user->loadMissing(['tags', 'interestGroups']);

        $connectedIds = $this->previouslyConnectedIds($user);
        $dismissedIds = SuggestionDismissal::where('user_id', $user->id)
            ->pluck('dismissed_user_id')->all();

        $myInterests = $this->tagIds($user, 'interest');
        $myWants = $this->tagIds($user, 'want_to_learn');
        $myOffers = array_merge($this->tagIds($user, 'can_help_with'), $this->tagIds($user, 'can_talk_about'));
        $myGroupIds = $user->interestGroups->pluck('id')->all();

        return User::query()
            ->active()
            ->whereKeyNot($user->id)
            ->whereNotIn('id', $dismissedIds)
            ->with(['tags', 'interestGroups'])
            ->get()
            ->map(function (User $candidate) use ($user, $connectedIds, $myInterests, $myWants, $myOffers, $myGroupIds) {
                $connected = in_array($candidate->id, $connectedIds, true);

                [$score, $reasons] = $this->score(
                    $user, $candidate, $connected, $myInterests, $myWants, $myOffers, $myGroupIds
                );

                return [
                    'user' => $candidate,
                    'score' => $score,
                    'reasons' => $reasons,
                    'previously_connected' => $connected,
                ];
            })
            ->sortByDesc('score')
            ->take($count)
            ->values();
    }

    /**
     * Everyone this user has already had a direct 1:1 connection with.
     *
     * Shared groups and shared events do not count - sitting in the same
     * WhatsApp group is not the same as having actually spoken.
     */
    public function previouslyConnectedIds(User $user): array
    {
        $ids = collect();

        $ids = $ids->concat(
            QuestTarget::whereHas('quest', fn ($q) => $q->where('user_id', $user->id))
                ->where('status', 'met')
                ->pluck('target_user_id')
        );

        MeetupPair::forUser($user->id)->get()->each(function ($pair) use (&$ids) {
            $ids->push($pair->user_one_id, $pair->user_two_id);
        });

        BuddyPairing::forUser($user->id)->get()->each(function ($pair) use (&$ids) {
            $ids->push($pair->user_one_id, $pair->user_two_id);
        });

        $ids = $ids
            ->concat(SessionRequest::where('requester_id', $user->id)->pluck('recipient_id'))
            ->concat(SessionRequest::where('recipient_id', $user->id)->pluck('requester_id'));

        // Office hours, in both directions.
        $ids = $ids->concat(
            OfficeHourBooking::where('user_id', $user->id)
                ->whereHas('slot')->with('slot')->get()->pluck('slot.host_id')
        );
        $ids = $ids->concat(
            OfficeHourBooking::whereIn(
                'office_hour_slot_id',
                OfficeHourSlot::where('host_id', $user->id)->select('id')
            )->pluck('user_id')
        );

        // Coffee invites: the host and everyone who joined alongside you.
        $myInviteIds = CoffeeInviteJoin::where('user_id', $user->id)->pluck('coffee_invite_id');
        $ids = $ids->concat(
            CoffeeInviteJoin::whereIn('coffee_invite_id', $myInviteIds)->pluck('user_id')
        );
        $ids = $ids->concat(
            CoffeeInvite::whereIn('id', $myInviteIds)->pluck('host_id')
        );
        $ids = $ids->concat(
            CoffeeInviteJoin::whereIn(
                'coffee_invite_id',
                CoffeeInvite::where('host_id', $user->id)->select('id')
            )->pluck('user_id')
        );

        return $ids->flatten()->unique()->reject(fn ($id) => $id === $user->id)->values()->all();
    }

    /** @return array{0: int, 1: array<int, string>} */
    protected function score(
        User $user,
        User $candidate,
        bool $connected,
        array $myInterests,
        array $myWants,
        array $myOffers,
        array $myGroupIds,
    ): array {
        $score = random_int(0, 4);
        $reasons = [];

        // Complementary knowledge - the strongest reason to meet someone.
        $theyCanHelp = $candidate->tags
            ->whereIn('id', $myWants)
            ->whereIn('pivot.kind', ['can_help_with', 'can_talk_about'])
            ->pluck('name');

        if ($theyCanHelp->isNotEmpty()) {
            $score += 40;
            $reasons[] = 'Can help with '.$theyCanHelp->take(2)->implode(' and ').', which you want to learn';
        }

        $youCanHelp = $candidate->tags
            ->where('pivot.kind', 'want_to_learn')
            ->whereIn('id', $myOffers)
            ->pluck('name');

        if ($youCanHelp->isNotEmpty()) {
            $score += 25;
            $reasons[] = 'Wants to learn '.$youCanHelp->take(2)->implode(' and ').', which you can help with';
        }

        $sharedInterests = $candidate->tags
            ->where('pivot.kind', 'interest')
            ->whereIn('id', $myInterests)
            ->pluck('name');

        if ($sharedInterests->isNotEmpty()) {
            $score += min(24, 8 * $sharedInterests->count());
            $reasons[] = 'You both listed '.$sharedInterests->take(2)->implode(' and ');
        }

        $sharedGroups = $candidate->interestGroups->whereIn('id', $myGroupIds)->pluck('name');

        if ($sharedGroups->isNotEmpty()) {
            $score += min(12, 6 * $sharedGroups->count());
            $reasons[] = 'Both in '.$sharedGroups->take(2)->implode(' and ');
        }

        if ($connected) {
            $score -= 60;
            $reasons[] = 'You have connected before';
        } else {
            $score += 30;
            $reasons[] = 'You have not crossed paths on Radix Connect yet';
        }

        if ($candidate->team && $candidate->team !== $user->team) {
            $score += 10;
            $reasons[] = 'Works in '.$candidate->team;
        }

        if ($candidate->location && $candidate->location !== $user->location) {
            $score += 8;
            $reasons[] = 'Based in '.$candidate->location;
        }

        return [$score, array_slice($reasons, 0, 3)];
    }

    protected function tagIds(User $user, string $kind): array
    {
        return $user->tags->where('pivot.kind', $kind)->pluck('id')->all();
    }
}
