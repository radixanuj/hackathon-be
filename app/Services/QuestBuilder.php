<?php

namespace App\Services;

use App\Models\Quest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Builds the New Joiner Quest: five people to meet in the first month.
 *
 * Suggestions deliberately cross teams, locations and tenure groups, and each
 * one carries a reason, because the point is not a directory listing - it is
 * answering "why might I want to talk to this person?".
 */
class QuestBuilder
{
    public const TARGET_COUNT = 5;

    public function buildFor(User $user, int $count = self::TARGET_COUNT): Quest
    {
        $quest = Quest::updateOrCreate(
            ['user_id' => $user->id],
            [
                'status' => 'active',
                'starts_at' => now(),
                'due_at' => now()->addDays(30),
                'completed_at' => null,
            ],
        );

        $quest->targets()->delete();

        foreach ($this->suggestFor($user, $count) as $suggestion) {
            $quest->targets()->create([
                'target_user_id' => $suggestion['user']->id,
                'reason' => $suggestion['reason'],
            ]);
        }

        return $quest->load('targets.targetUser');
    }

    /** @return Collection<int, array{user: User, reason: string, score: int}> */
    public function suggestFor(User $user, int $count = self::TARGET_COUNT): Collection
    {
        $user->loadMissing('tags');
        $myTagIds = $user->tags->pluck('id')->all();

        $candidates = User::query()
            ->active()
            ->whereKeyNot($user->id)
            ->with('tags')
            ->get();

        $scored = $candidates->map(function (User $candidate) use ($user, $myTagIds) {
            [$score, $reason] = $this->score($user, $candidate, $myTagIds);

            return ['user' => $candidate, 'reason' => $reason, 'score' => $score];
        });

        // Spread the five picks across teams so a new joiner does not just meet
        // five people from the department next door.
        return $this->diversify($scored->sortByDesc('score')->values(), $count);
    }

    /** @return array{0: int, 1: string} */
    protected function score(User $user, User $candidate, array $myTagIds): array
    {
        $score = random_int(0, 5); // keeps repeat runs from being identical
        $reasons = [];

        $sharedInterests = $candidate->tags
            ->whereIn('id', $myTagIds)
            ->where('pivot.kind', 'interest')
            ->pluck('name');

        if ($sharedInterests->isNotEmpty()) {
            $score += 25;
            $reasons[] = 'You both listed '.$sharedInterests->take(2)->implode(' and ');
        }

        // Someone who can help with what the new joiner wants to learn.
        $wanted = $user->tags->where('pivot.kind', 'want_to_learn')->pluck('id')->all();
        $canHelp = $candidate->tags
            ->whereIn('id', $wanted)
            ->whereIn('pivot.kind', ['can_help_with', 'can_talk_about'])
            ->pluck('name');

        if ($canHelp->isNotEmpty()) {
            $score += 35;
            $reasons[] = 'They can help with '.$canHelp->take(2)->implode(' and ').', which you want to learn';
        }

        if ($candidate->team && $candidate->team !== $user->team) {
            $score += 20;
            $reasons[] = 'Works in '.$candidate->team.', a team you would not run into day to day';
        }

        if ($candidate->location && $candidate->location !== $user->location) {
            $score += 15;
            $reasons[] = 'Based in '.$candidate->location;
        }

        if ($candidate->tenureBand() === 'senior') {
            $score += 20;
            $reasons[] = 'Has been at Radix '.$candidate->tenureYears().' years and has seen a lot of it';
        }

        if ($reasons === []) {
            $talks = $candidate->tags->where('pivot.kind', 'can_talk_about')->pluck('name');
            $reasons[] = $talks->isNotEmpty()
                ? 'Happy to talk about '.$talks->take(2)->implode(' and ')
                : 'A good person to know across the company';
        }

        return [$score, implode('. ', array_slice($reasons, 0, 2)).'.'];
    }

    /**
     * Pick the highest scorers while allowing at most one person per team until
     * every team is used up.
     *
     * @param  Collection<int, array{user: User, reason: string, score: int}>  $ranked
     * @return Collection<int, array{user: User, reason: string, score: int}>
     */
    protected function diversify(Collection $ranked, int $count): Collection
    {
        $picked = collect();
        $usedTeams = [];

        foreach ($ranked as $candidate) {
            if ($picked->count() >= $count) {
                break;
            }

            $team = $candidate['user']->team ?? '__none__';

            if (in_array($team, $usedTeams, true)) {
                continue;
            }

            $usedTeams[] = $team;
            $picked->push($candidate);
        }

        // Not enough distinct teams? Top up with the next best people regardless.
        if ($picked->count() < $count) {
            $pickedIds = $picked->pluck('user.id')->all();

            $picked = $picked->concat(
                $ranked->reject(fn ($c) => in_array($c['user']->id, $pickedIds, true))
                    ->take($count - $picked->count())
            );
        }

        return $picked->values();
    }
}
