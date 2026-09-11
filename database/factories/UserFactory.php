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

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(config('radix.demo_login.password', 'Radix123')),
            'remember_token' => Str::random(10),
            'job_title' => fake()->jobTitle(),
            'team' => fake()->randomElement(self::TEAMS),
            'location' => fake()->randomElement(self::LOCATIONS),
            'timezone' => 'Asia/Kolkata',
            'joined_at' => fake()->dateTimeBetween('-11 years', '-1 month')->format('Y-m-d'),
            'intro' => fake()->sentence(14),
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
