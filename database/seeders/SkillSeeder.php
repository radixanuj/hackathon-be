<?php

namespace Database\Seeders;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Puts each employee's real skills on their profile.
 *
 * The export is a flat map of full name to a list of skills. The first few become
 * `skill` tags and land under two of the four profile sections: they all go under
 * "can help with", and the first three also go under "can talk about", which is
 * what drives Who Should I Meet? and Ask Radix routing.
 *
 * Anything the DatabaseSeeder had put on those two sections at random is cleared
 * first — a real skill list is strictly better than a made-up one, and leaving
 * both would read as though everybody knows everything. "Want to learn" and
 * interests are left alone: this export says nothing about either.
 */
class SkillSeeder extends Seeder
{
    protected const SOURCE = 'database/data/skills.json';

    /**
     * How many skills to take off a person's list.
     *
     * The export runs to seventy for some people, which is a wall of chips on a
     * profile and tells a reader nothing. The first few are the specific ones —
     * the generic "Problem Solving, Adaptability, Teamwork" tail comes last — so
     * taking from the front keeps what actually distinguishes somebody.
     */
    protected const MAX_SKILLS = 5;

    /** How many of those also become "can talk about". */
    protected const HEADLINE = 3;

    /** The app validates tags at 60 characters; a few exported skills are essays. */
    protected const MAX_NAME = 60;

    public function run(): void
    {
        $export = $this->read();
        $index = $this->indexUsers();

        $matched = 0;
        $unmatched = [];
        $ambiguous = [];

        foreach ($export as $fullName => $skills) {
            $user = $this->matchUser($fullName, $index, $ambiguous);

            if (! $user) {
                $unmatched[] = $fullName;

                continue;
            }

            $this->applyTo($user, $skills);
            $matched++;
        }

        $this->report($matched, $export->count(), $unmatched, $ambiguous);
    }

    /** Replace the two expertise sections with what the person actually listed. */
    protected function applyTo(User $user, array $skills): void
    {
        // Deduplicated on the resolved tag, not the string: tags are keyed by slug,
        // so "Problem Solving" and "Problem-Solving" are one tag and attaching both
        // would collide on the (user, tag, kind) unique index.
        $tags = collect($skills)
            ->map(fn (string $skill) => $this->cleanName($skill))
            ->filter()
            ->map(fn (string $name) => Tag::findOrCreateByName($name, 'skill'))
            ->unique->id;

        $tags = $tags->take(self::MAX_SKILLS);

        if ($tags->isEmpty()) {
            return;
        }

        // detach() on the pivot, not sync(): the same user_tag row carries a kind,
        // and syncing would take their interests and want-to-learn tags with it.
        $user->tags()->wherePivotIn('kind', ['can_talk_about', 'can_help_with'])->detach();

        foreach ($tags as $tag) {
            $user->tags()->attach($tag->id, ['kind' => 'can_help_with']);
        }

        foreach ($tags->take(self::HEADLINE) as $tag) {
            $user->tags()->attach($tag->id, ['kind' => 'can_talk_about']);
        }
    }

    /**
     * Resolve an exported name to somebody on the roster.
     *
     * The two files disagree about names in small ways — the skills export carries
     * middle names the org chart drops, spaces "De Souza" where the chart writes
     * "DeSouza", and has at least one person's names the other way round. Each rung
     * of the ladder is tried in full before the next, and a rung that matches more
     * than one person is refused rather than guessed at.
     */
    protected function matchUser(string $fullName, Collection $index, array &$ambiguous): ?User
    {
        foreach (self::keysFor($fullName) as $strategy => $key) {
            $hits = $index->get($strategy.'|'.$key, collect());

            if ($hits->count() === 1) {
                return $hits->first();
            }

            if ($hits->count() > 1) {
                $ambiguous[] = $fullName.' → '.$hits->pluck('name')->implode(', ');

                return null;
            }
        }

        return null;
    }

    /** Every roster name under every key it could be matched by. */
    protected function indexUsers(): Collection
    {
        $index = collect();

        foreach (User::all() as $user) {
            foreach (self::keysFor($user->name) as $strategy => $key) {
                $bucket = $strategy.'|'.$key;

                $index->put($bucket, $index->get($bucket, collect())->push($user));
            }
        }

        return $index;
    }

    /**
     * The match keys for one name, loosest last.
     *
     * @return array<string, string>
     */
    protected static function keysFor(string $name): array
    {
        $tokens = preg_split('/[^a-z]+/', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return [];
        }

        $first = $tokens[0];
        $last = end($tokens);
        $sorted = $tokens;
        sort($sorted);
        $endsSorted = [$first, $last];
        sort($endsSorted);

        return [
            'exact' => implode(' ', $tokens),
            // "Clifford De Souza" and "Clifford DeSouza".
            'squashed' => implode('', $tokens),
            // "Avaneesh Veereshwar Sharma" and "Avaneesh Sharma".
            'ends' => $first.' '.$last,
            // "Mohammad Aqueeb" and "Aqueeb Mohammad".
            'anyorder' => implode(' ', $sorted),
            'endsanyorder' => implode(' ', $endsSorted),
        ];
    }

    /**
     * Tidy one exported skill into a tag name.
     *
     * " and " becomes " & " so "Adaptability and Resilience" lands on the same tag
     * as "Adaptability & Resilience" — the same normalisation EmployeeSeeder applies
     * to departments. Overlong entries are trimmed at a word boundary rather than
     * dropped, so the skill still shows up and stays editable through the API.
     */
    protected function cleanName(string $skill): ?string
    {
        $name = preg_replace('/\s+/', ' ', trim($skill)) ?? '';
        $name = str_ireplace(' and ', ' & ', $name);

        if ($name === '') {
            return null;
        }

        if (mb_strlen($name) > self::MAX_NAME) {
            $cut = mb_substr($name, 0, self::MAX_NAME);
            $space = mb_strrpos($cut, ' ');
            $name = rtrim($space ? mb_substr($cut, 0, $space) : $cut, " ,-–(&");
        }

        return $name;
    }

    protected function read(): Collection
    {
        $path = base_path(self::SOURCE);

        if (! is_file($path)) {
            throw new RuntimeException('Skills export not found at '.self::SOURCE.'.');
        }

        return collect(json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
    }

    /** Say what was skipped. A silently dropped person is a profile nobody notices is empty. */
    protected function report(int $matched, int $total, array $unmatched, array $ambiguous): void
    {
        $this->command?->info("Skills: tagged {$matched} of {$total} people from the export.");

        if ($ambiguous !== []) {
            $this->command?->warn('  Ambiguous, skipped: '.implode('; ', $ambiguous));
        }

        if ($unmatched !== []) {
            $this->command?->warn(
                '  Not on the roster, skipped ('.count($unmatched).'): '.implode(', ', $unmatched)
            );
        }
    }
}
