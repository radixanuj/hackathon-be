<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Seeds the real Radix roster from the org-chart export.
 *
 * A row carries a name, a title, an office, and - where HR has exported it - a
 * `startDate`. Whatever it doesn't carry is derived from the title: team, tenure,
 * mentoring preferences. Those derivations are keyed off the person's name rather
 * than random, so reseeding lands on the same roster instead of quietly reshuffling
 * everyone's history.
 *
 * Real data always wins over a derivation, and is written back on every run. Derived
 * values are only a starting point, set on the way in and never overwritten - so a
 * profile someone filled in during a demo survives the next reseed.
 */
class EmployeeSeeder extends Seeder
{
    protected const SOURCE = 'database/data/employees.json';

    /**
     * The optional real join date, and the formats we'll read it in.
     *
     * Day-first is tried before month-first, since that is how the HR exports here
     * are written - "3/4/2019" is the third of April. Anything that matches none of
     * these is an error rather than a silent fallback to a made-up tenure.
     */
    protected const START_DATE = 'startDate';

    protected const START_DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd-M-Y', 'd M Y', 'd F Y', 'M j, Y', 'F j, Y'];

    /** Hashed once and shared: bcrypt per person turns a 1s seed into a 15s one. */
    protected static ?string $password = null;

    protected const EMAIL_DOMAIN = 'radix.email';

    /** The export includes its own root node, named after the office. Not a person. */
    protected const NOT_PEOPLE = ['head of organization'];

    /** Addresses the docs and Postman collection hand out, so they can't be slug-derived. */
    protected const OVERRIDES = [
        'Anuj Maurya' => [
            'email' => 'admin@radix.email',
            'role' => 'admin',
            'intro' => 'Building Radix Connect. Ask me about backends, hiring and long treks.',
        ],
    ];

    /** Department - the half of a title after the dash - to the team we file it under. */
    protected const TEAMS = [
        'software development engineer' => 'Engineering',
        'software development engineering' => 'Engineering',
        'technical support' => 'Support',
        'data analytics' => 'Data',
        'data science' => 'Data',
        'data sciences' => 'Data',
        'data visualization' => 'Data',
        'finance' => 'Finance',
        'financial strategy & business analysis' => 'Finance',
        'people success' => 'People',
        'talent acquisition' => 'People',
        'talent management' => 'People',
        'trust & safety' => 'Trust & Safety',
        'ui design' => 'Design',
        'visual design' => 'Design',
        'brand management & strategy' => 'Marketing',
        'brand marketing' => 'Marketing',
        'channel marketing' => 'Marketing',
        'consumer insights marketing' => 'Marketing',
        'content marketing & communication' => 'Marketing',
        'creative & marketing strategy' => 'Marketing',
        'digital marketing' => 'Marketing',
        'marcomm & ci' => 'Marketing',
        'online marketing & ai strategy' => 'Marketing',
        'marqee partnerships' => 'Partnerships', // Typo in the export.
        'marquee partnerships' => 'Partnerships',
        'premium domains' => 'Partnerships',
        'strategic partnerships' => 'Partnerships',
        'business strategy & program management' => 'Programs',
        'project management' => 'Programs',
        'strategic programs' => 'Programs',
        'operations' => 'Operations',
    ];

    /**
     * The export's own Department column, mapped onto the team names the app files
     * people under. Every department in the export is listed, so `Str::title` on an
     * unmapped one only ever catches a department HR adds after this was written.
     */
    protected const DEPARTMENT_TEAMS = [
        'engineering' => 'Engineering',
        'data science' => 'Data Science',
        'design' => 'Design',
        'marketing' => 'Marketing',
        'brands' => 'Brands',
        'channel' => 'Channel',
        'special projects' => 'Special Projects',
        'people success' => 'People Success',
        'finance' => 'Finance',
        'trust & safety' => 'Trust & Safety',
        'customer success' => 'Customer Success',
        'corp it' => 'Corp IT',
        'real estate & workplace' => 'Real Estate & Workplace',
        'executive management' => 'Executive Management',
    ];

    /** Office to the timezone people there actually work in. */
    protected const TIMEZONES = [
        'Mumbai' => 'Asia/Kolkata',
        'Dubai' => 'Asia/Dubai',
        'Cayman' => 'America/Cayman',
        'Vancouver' => 'America/Vancouver',
        'Beijing' => 'Asia/Shanghai',
        'Sau Paulo' => 'America/Sao_Paulo',
    ];

    protected const DEFAULT_TIMEZONE = 'Asia/Kolkata';

    /** Titles carrying no department still belong somewhere. */
    protected const TEAMS_BY_RANK = [
        'chief executive officer' => 'Leadership',
        'consultant' => 'Advisory',
        'consulting advisor' => 'Advisory',
    ];

    /** Rank to a plausible tenure window in years - the Blind Meetup split needs both halves. */
    protected const TENURE = [
        'chief executive officer' => [15, 19],
        'senior vice president' => [11, 16],
        'vice president' => [10, 14],
        'senior director' => [8, 13],
        'director' => [7, 11],
        'associate director' => [5, 9],
        'senior manager' => [4, 8],
        'manager' => [3, 6],
        'senior specialist' => [2, 5],
        'senior associate' => [1, 4],
        'specialist' => [1, 3],
        'consultant' => [1, 4],
        'consulting advisor' => [1, 4],
    ];

    protected const DEFAULT_TENURE = [1, 5];

    /** Ranks senior enough that mentoring is part of the job. */
    protected const MENTORING_RANKS = [
        'chief executive officer', 'senior vice president', 'vice president', 'senior director',
        'director', 'associate director', 'senior manager', 'manager',
    ];

    /** Ranks junior enough to plausibly have joined this month. */
    protected const JUNIOR_RANKS = ['senior associate', 'specialist', 'senior specialist'];

    /** How many of those juniors start as new joiners, so the New Joiner Quest has takers. */
    protected const NEW_JOINERS = 5;

    public function run(): void
    {
        $people = $this->read();

        // Only people the export gave no date for: a real start date is never overridden
        // to manufacture a new joiner.
        $newJoiners = $people->filter(fn (array $p) => $p['startDate'] === null
            && in_array($p['rank'], self::JUNIOR_RANKS, true))
            ->sortBy(fn (array $p) => $this->roll($p['fullName'], 'newjoiner'))
            ->take(self::NEW_JOINERS)
            ->pluck('fullName')
            ->all();

        foreach ($people as $person) {
            $this->upsert($person, in_array($person['fullName'], $newJoiners, true));
        }
    }

    /**
     * Creates a person, or tops up the one already there.
     *
     * Facts the org chart owns - name, title, team, office - are always written back.
     * Everything else is a starting point we only set on the way in, so a profile
     * someone has filled in during a demo survives the next reseed.
     */
    protected function upsert(array $person, bool $isNewJoiner): User
    {
        $override = self::OVERRIDES[$person['fullName']] ?? [];

        $user = User::firstOrNew(['email' => $this->emailFor($person)]);

        $user->fill([
            'name' => $person['fullName'],
            'job_title' => $person['title'],
            'team' => $this->teamFor($person),
            'location' => $person['location'],
            'timezone' => $this->timezoneFor($person),
            'role' => $override['role'] ?? 'employee',
            'is_active' => true,
        ]);

        // An exported start date is a fact about the person, so it is kept in step on
        // every run. A derived one is a guess, and only fills an empty profile.
        if ($person['startDate'] !== null) {
            $user->joined_at = $person['startDate'];
        } elseif (! $user->exists) {
            $user->joined_at = $this->joinedAt($person, $isNewJoiner);
        }

        if (! $user->exists) {
            $user->fill([
                'password' => static::$password ??= Hash::make(config('radix.demo_login.password', 'Radix123')),
                'email_verified_at' => now(),
                'remember_token' => Str::random(10),
                'intro' => $override['intro'] ?? null,
                'open_to_mentoring' => in_array($person['rank'], self::MENTORING_RANKS, true)
                    || $this->roll($person['fullName'], 'mentoring') < 70,
                'open_to_blind_meetups' => $this->roll($person['fullName'], 'meetups') < 80,
            ]);
        }

        $user->save();

        return $user;
    }

    /** The export, minus its root node, with each title split into rank and department. */
    protected function read(): Collection
    {
        $path = base_path(self::SOURCE);

        if (! is_file($path)) {
            throw new RuntimeException('Employee export not found at '.self::SOURCE.'.');
        }

        $rows = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return collect($rows)
            // array_merge, not "+": the parsed date has to overwrite the raw string the
            // export came with, and a union would keep the left-hand value instead.
            ->map(fn (array $row) => array_merge($row, $this->splitTitle($row['title']), [
                'startDate' => $this->parseStartDate($row),
            ]))
            ->reject(fn (array $row) => in_array($row['rank'], self::NOT_PEOPLE, true))
            ->values();
    }

    /**
     * "Senior Specialist- Software Development Engineering" becomes a rank and a
     * department. The export spaces that dash three different ways, and writes "and"
     * where it elsewhere writes "&", so both are normalised away before matching.
     */
    protected function splitTitle(string $title): array
    {
        $parts = preg_split('/\s*-\s*/', trim($title), 2);

        $normalise = fn (string $value) => $this->normalise($value);

        return [
            'rank' => $normalise($parts[0]),
            'titleDepartment' => isset($parts[1]) ? $normalise($parts[1]) : null,
        ];
    }

    protected function teamFor(array $person): string
    {
        // The export names the department outright, so the title is only read where
        // it doesn't - a department column is a fact, a parsed title is a guess.
        $department = $this->normalise((string) ($person['department'] ?? ''));

        if ($department !== '') {
            return self::DEPARTMENT_TEAMS[$department] ?? Str::title($department);
        }

        if ($person['titleDepartment'] === null) {
            return self::TEAMS_BY_RANK[$person['rank']] ?? 'Leadership';
        }

        // An unmapped department becomes its own team rather than a silent null.
        return self::TEAMS[$person['titleDepartment']] ?? Str::title($person['titleDepartment']);
    }

    /** Where the person actually sits, so a Dubai profile doesn't report IST. */
    protected function timezoneFor(array $person): string
    {
        return self::TIMEZONES[$person['location']] ?? self::DEFAULT_TIMEZONE;
    }

    protected function normalise(string $value): string
    {
        return str_replace(' and ', ' & ', mb_strtolower(trim($value)));
    }

    /**
     * The fallback for anyone the export gave no start date: a join date the title
     * makes believable, or a recent one for the designated new joiners.
     */
    protected function joinedAt(array $person, bool $isNewJoiner): string
    {
        if ($isNewJoiner) {
            return now()->subDays(5 + $this->roll($person['fullName'], 'recent') % 35)->toDateString();
        }

        [$min, $max] = self::TENURE[$person['rank']] ?? self::DEFAULT_TENURE;

        $days = (int) round(($min + ($max - $min) * $this->roll($person['fullName'], 'tenure') / 99) * 365.25);

        return now()->subDays($days)->toDateString();
    }

    /**
     * The exported start date, or null where the export has none.
     *
     * A date that is present but unreadable throws: silently falling back to an
     * invented tenure would put a wrong join date on a real person's profile, and
     * nothing downstream would ever flag it.
     */
    protected function parseStartDate(array $row): ?string
    {
        $value = trim((string) ($row[self::START_DATE] ?? ''));

        if ($value === '') {
            return null;
        }

        foreach (self::START_DATE_FORMATS as $format) {
            try {
                // The leading "!" zeroes out the time, so a date is just a date.
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (InvalidArgumentException) {
                continue;
            }

            // A format can "match" and still be wrong - "13/04/2019" read as m/d/Y
            // leaves a warning rather than failing - so only a clean parse counts.
            $errors = Carbon::getLastErrors() ?: [];

            if ($date !== false && ($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                return $date->toDateString();
            }
        }

        throw new RuntimeException(
            'Unreadable '.self::START_DATE." \"{$value}\" for {$row['fullName']}. Expected one of: "
            .implode(', ', self::START_DATE_FORMATS).'.'
        );
    }

    protected function emailFor(array $person): string
    {
        return self::OVERRIDES[$person['fullName']]['email']
            ?? Str::slug($person['fullName']).'@'.self::EMAIL_DOMAIN;
    }

    /** A stable 0-99 roll per person, so a reseed produces the same roster. */
    protected function roll(string $name, string $salt): int
    {
        return crc32($name.'|'.$salt) % 100;
    }
}
