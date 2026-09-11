<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Fills the "Currently into" card on every profile that hasn't got one.
 *
 * The card is the part of a profile that dates it on purpose — a book, a race,
 * a show — and it is what gives someone an opening line that isn't their job
 * title. Seventy empty cards would make the section look like a feature nobody
 * uses, so the roster ships with them filled.
 *
 * Lines are drawn from the interests the person actually carries, so a profile
 * that says Trekking outside work is training for a trek rather than a 10k. The
 * pick is a hash of the person's name, so a reseed lands on the same lines.
 *
 * Only ever fills a blank. Anything typed during a demo survives.
 */
class CurrentlySeeder extends Seeder
{
    /** Interest tag => the lines somebody with that interest would plausibly write. */
    protected const BY_INTEREST = [
        'Books' => ['Reading', ['Project Hail Mary', 'The Wager', 'A Fine Balance, again', 'Thinking in Bets', 'The Idea Factory']],
        'F1' => ['Watching', ['Every race twice, practice included', 'The season slip away from my team', 'Qualifying at unreasonable hours']],
        'Cricket' => ['Following', ['Every ball of the series', 'The Ranji scores far too closely', 'A Test that will probably be a draw']],
        'Running' => ['Training for', ['A 10k in November', 'My first half marathon', 'A sub-25 5k, optimistically']],
        'Trekking' => ['Training for', ['Ben Nevis in October', 'Annapurna, part two', 'A Sahyadri traverse before the rains']],
        'Photography' => ['Shooting', ['Doorways, badly', 'Film again, at ruinous expense', 'Everything on one 35mm lens']],
        'AI' => ['Building', ['Small, useless things with LLMs', 'A weekend agent that mostly works', 'An embeddings toy nobody asked for']],
        'Movies' => ['Watching', ['Everything Wong Kar-wai', 'Severance, one episode a night', 'The Bear, slowly']],
        'Gaming' => ['Playing', ['Balatro, too much', 'A backlog I will never finish', 'Forty hours into an RPG I cannot explain']],
        'Cooking' => ['Perfecting', ['A 70% hydration loaf', 'A biryani that takes all Sunday', 'The one curry my mother still corrects']],
        'Chess' => ['Playing', ['Rapid games on the commute', 'The same opening until it works', 'Puzzles instead of sleeping']],
        'Cycling' => ['Riding', ['Early on Sundays, before the traffic', 'To work, weather permitting', 'The long way home most evenings']],
        'Music' => ['Listening to', ['Anything with a horn section', 'Acquired, on repeat', 'One album a week, properly']],
        'Board Games' => ['Playing', ['Whatever I can talk people into', 'A campaign game I will teach anyone', 'Too much Wingspan']],
        'Travel' => ['Planning', ['A trip I have not booked yet', 'Two weeks I cannot take off', 'Japan, for the fourth year running']],
    ];

    /** For people whose interests we have nothing written for. */
    protected const GENERIC = [
        ['Reading', ['Two books at once, finishing neither', 'Whatever the team book channel insists on']],
        ['Watching', ['Something everybody else finished a year ago']],
        ['Learning', ['An instrument, badly, for the third time']],
    ];

    public function run(): void
    {
        User::with('tags')->get()->each(function (User $user) {
            if ($user->currentlyEntries() !== []) {
                return;
            }

            $user->update(['currently' => $this->composeFor($user)]);
        });
    }

    /** Two or three lines: enough to read as a person, few enough to scan. */
    protected function composeFor(User $user): array
    {
        $interests = $user->tags
            ->filter(fn ($tag) => $tag->pivot->kind === 'interest')
            ->pluck('name')
            ->filter(fn (string $name) => isset(self::BY_INTEREST[$name]))
            ->values();

        $wanted = $this->roll($user, 'count') < 55 ? 3 : 2;

        $lines = $interests
            ->take($wanted)
            ->map(fn (string $name) => $this->line($user, self::BY_INTEREST[$name], $name));

        // Nobody should end up with a card carrying a single line.
        while ($lines->count() < 2) {
            $fallback = self::GENERIC[$lines->count() % count(self::GENERIC)];
            $lines->push($this->line($user, $fallback, 'generic'.$lines->count()));
        }

        return $lines->values()->all();
    }

    /** @param array{0: string, 1: array<int, string>} $source */
    protected function line(User $user, array $source, string $salt): array
    {
        [$label, $options] = $source;

        return [
            'icon' => User::iconFor($label),
            'label' => $label,
            'value' => $options[$this->roll($user, $salt) % count($options)],
        ];
    }

    /** A stable 0-99 roll per person, so a reseed produces the same card. */
    protected function roll(User $user, string $salt): int
    {
        return crc32($user->name.'|'.$user->team.'|currently|'.$salt) % 100;
    }
}
