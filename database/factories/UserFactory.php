<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    public const TEAMS = [
        'Engineering', 'Product', 'Design', 'Marketing', 'Sales', 'Finance',
        'People', 'Legal', 'Support', 'Data',
    ];

    public const LOCATIONS = ['Mumbai', 'Pune', 'Bengaluru', 'Dubai', 'London', 'Remote'];

    /**
     * Titles in the shape the real roster uses - "Rank - Department".
     *
     * Faker's jobTitle() hands back things like "Gluing Machine Operator", which
     * reads as obvious filler next to seventy exported colleagues, and the team
     * derivation downstream has nothing to do with it.
     */
    public const JOB_TITLES = [
        'Senior Associate - Software Development Engineering',
        'Manager - Software Development Engineering',
        'Senior Specialist - Data Analytics',
        'Specialist - Digital Marketing',
        'Senior Manager - Strategic Partnerships',
        'Associate Director - Brand Marketing',
        'Senior Associate - Technical Support',
        'Manager - People Success',
        'Specialist - Visual Design',
        'Senior Specialist - Financial Strategy & Business Analysis',
    ];

    /**
     * Intros in the register a colleague actually writes in.
     *
     * The intro is quoted on Home, under every name in the mentoring list, and at
     * the top of the profile modal - a lorem sentence there is the first thing a
     * viewer reads and the loudest possible tell that nothing here is real.
     */
    public const INTROS = [
        'Mostly backend, mostly the parts nobody volunteers for. Ask me before you add another queue.',
        'I spend my week between the data and the creative, translating one into the other.',
        'Came up through the work before managing it, so I am still close enough to be useful in a review.',
        'Happy to talk to anyone about anything, including plenty outside my job description.',
        'I build the reporting most teams open on a Monday. If a number looks wrong, it is probably mine.',
        'Reasonably new here, so mostly asking questions and writing down the answers.',
        'Most of what I do is unblocking other people, which is a better use of me than anything else.',
        'I care a great deal about type and spacing and have accepted this is not universal.',
        'Six years of talking to customers on their worst day. I know where the product confuses people.',
        'Come to me with the half-formed version — I would rather see it early than polished and wrong.',
    ];

    public function definition(): array
    {
        return [
            'name' => fake()->firstName().' '.fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(config('radix.demo_login.password', 'Radix123')),
            'remember_token' => Str::random(10),
            'job_title' => fake()->randomElement(self::JOB_TITLES),
            'team' => fake()->randomElement(self::TEAMS),
            'location' => fake()->randomElement(self::LOCATIONS),
            'timezone' => 'Asia/Kolkata',
            'joined_at' => fake()->dateTimeBetween('-11 years', '-1 month')->format('Y-m-d'),
            'intro' => fake()->randomElement(self::INTROS),
            'avatar_url' => null,
            'role' => 'employee',
            'is_active' => true,
            'open_to_mentoring' => fake()->boolean(80),
            'open_to_blind_meetups' => fake()->boolean(75),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin']);
    }

    /** 6+ years at Radix - the senior half of the Blind Meetup split. */
    public function veteran(): static
    {
        return $this->state(fn () => [
            'joined_at' => fake()->dateTimeBetween('-14 years', '-7 years')->format('Y-m-d'),
        ]);
    }

    public function newJoiner(): static
    {
        return $this->state(fn () => [
            'joined_at' => fake()->dateTimeBetween('-25 days', 'now')->format('Y-m-d'),
        ]);
    }
}
