<?php

namespace Database\Seeders;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The two curated lists behind Pick a Brain: who mentors, and what people ask about.
 *
 * Both are business decisions rather than anything derivable from the org chart,
 * so they live here as named constants instead of a heuristic over titles. The
 * seeder is authoritative in both directions - it clears the roster and the
 * featured row before writing them - so removing a name here removes them from
 * the product on the next run, rather than leaving a stale mentor behind.
 */
class MentoringSeeder extends Seeder
{
    /**
     * The mentoring roster.
     *
     * Distinct from `open_to_mentoring`, which most of the company carries and
     * which Knowledge Sessions and Coaching run on. These are the people who have
     * agreed to take a standing mentoring relationship.
     */
    protected const MENTORS = [
        'Karn Jajoo',
        'Arjava Vig',
        'Namrata Arya',
        'Parag Barhate',
        'Vishal Pansari',
        'Neha Vijay',
    ];

    /**
     * The topics on the Knowledge Session card, in the order they appear.
     *
     * Each carries the teams and ranks the skill is believable for. Two of these
     * are already well represented in the skills export; the rest are new, and
     * would otherwise render as a chip that leads to an empty list - so anyone
     * short of MIN_HOLDERS is topped up from its own pool.
     */
    protected const TOPICS = [
        'People Management' => ['ranks' => ['director', 'vice president', 'chief', 'manager', 'head']],
        'Python' => ['teams' => ['Engineering', 'Data']],
        'Prompt Engineering' => ['teams' => ['Engineering', 'Data', 'Marketing']],
        'Vibe coding' => ['teams' => ['Engineering', 'Design', 'Data', 'Programs']],
        'UX Writing' => ['teams' => ['Design', 'Marketing']],
        'Hiring' => ['teams' => ['People'], 'ranks' => ['director', 'vice president', 'chief', 'manager']],
        'Negotiation' => ['teams' => ['Partnerships', 'Finance', 'Leadership', 'Advisory']],
    ];

    /** How many people a topic needs before its chip is worth showing. */
    protected const MIN_HOLDERS = 8;

    public function run(): void
    {
        $this->seedRoster();
        $this->seedTopics();
    }

    /** Exactly the named handful, and nobody else. */
    protected function seedRoster(): void
    {
        $mentors = collect(self::MENTORS)->map(fn (string $name) => $this->rosterMember($name));

        User::query()->whereNotIn('id', $mentors->pluck('id'))->update(['is_mentor' => false]);

        // A mentor who cannot be asked is a dead card, and the roster is the
        // stronger statement of intent of the two flags.
        User::query()->whereIn('id', $mentors->pluck('id'))
            ->update(['is_mentor' => true, 'open_to_mentoring' => true]);

        $this->command?->info('Mentoring: roster set to '.$mentors->pluck('name')->implode(', ').'.');
    }

    /** The featured row, plus enough people behind each chip to be worth tapping. */
    protected function seedTopics(): void
    {
        Tag::query()->whereNotNull('featured_rank')->update(['featured_rank' => null]);

        $roster = User::query()->active()->get();
        $rank = 0;

        foreach (self::TOPICS as $name => $pool) {
            $tag = Tag::findOrCreateByName($name, 'skill');
            $tag->update(['featured_rank' => ++$rank]);

            $this->topUp($tag, $roster, $pool);

            $tag->update(['usage_count' => $tag->users()->count()]);
        }

        $this->command?->info('Mentoring: featured '.count(self::TOPICS).' knowledge-session topics.');
    }

    /**
     * Put a topic on enough believable profiles to clear MIN_HOLDERS.
     *
     * Candidates are ordered by a stable per-person roll rather than at random, so
     * a reseed lands on the same faces instead of quietly reshuffling who knows
     * Python. Only the shortfall is written: people who already listed the skill
     * themselves keep it, and are counted first.
     */
    protected function topUp(Tag $tag, Collection $roster, array $pool): void
    {
        $holders = $tag->users()->wherePivot('kind', 'can_help_with')->pluck('users.id');

        $shortfall = self::MIN_HOLDERS - $holders->count();

        if ($shortfall <= 0) {
            return;
        }

        $candidates = $roster
            ->reject(fn (User $user) => $holders->contains($user->id))
            ->filter(fn (User $user) => $this->fits($user, $pool))
            ->sortBy(fn (User $user) => crc32($user->name.'|'.$tag->slug))
            ->take($shortfall);

        foreach ($candidates as $user) {
            // attach() per kind, not sync(): the pivot carries the profile section,
            // and the same row would otherwise be moved rather than added.
            $user->tags()->attach($tag->id, ['kind' => 'can_help_with']);
            $user->tags()->attach($tag->id, ['kind' => 'can_talk_about']);
        }
    }

    /** Whether this topic is plausible on this person's profile. */
    protected function fits(User $user, array $pool): bool
    {
        if (in_array($user->team, $pool['teams'] ?? [], true)) {
            return true;
        }

        $title = mb_strtolower((string) $user->job_title);

        foreach ($pool['ranks'] ?? [] as $rank) {
            if (str_contains($title, $rank)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One roster name from the list above.
     *
     * The list is written the way people are spoken about rather than the way the
     * org chart spells them - "Neha Vijay" for "Neha Vijay Nair" - so an exact
     * match is tried first and a unique prefix second. Anything else throws: a
     * mentor who silently failed to resolve is a card that quietly loses a name.
     */
    protected function rosterMember(string $name): User
    {
        $exact = User::query()->where('name', $name)->get();

        if ($exact->count() === 1) {
            return $exact->first();
        }

        $prefixed = User::query()->where('name', 'like', $name.' %')->get();

        if ($prefixed->count() === 1) {
            return $prefixed->first();
        }

        throw new RuntimeException($prefixed->count() > 1
            ? "Mentor \"{$name}\" matches more than one person: ".$prefixed->pluck('name')->implode(', ').'.'
            : "Mentor \"{$name}\" is not on the roster.");
    }
}
