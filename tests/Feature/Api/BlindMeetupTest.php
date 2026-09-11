<?php

namespace Tests\Feature\Api;

use App\Models\MeetupRound;
use App\Models\User;
use App\Services\BlindMeetupMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlindMeetupTest extends TestCase
{
    use RefreshDatabase;

    protected function openRound(): MeetupRound
    {
        return MeetupRound::create([
            'title' => 'Blind Meetups · Test',
            'period' => now()->format('Y-m'),
            'signups_open_at' => now()->subDays(3),
            'signups_close_at' => now()->addDays(3),
            'meetup_date' => now()->addDays(10),
            'status' => 'open',
        ]);
    }

    public function test_it_pairs_across_the_six_year_tenure_split(): void
    {
        $round = $this->openRound();

        $veterans = User::factory()->count(3)->veteran()->create(['team' => 'Engineering']);
        $newer = User::factory()->count(3)->create([
            'team' => 'Sales',
            'joined_at' => now()->subYears(2),
        ]);

        foreach ($veterans->concat($newer) as $user) {
            $round->signups()->create(['user_id' => $user->id]);
        }

        $pairs = app(BlindMeetupMatcher::class)->match($round);

        $this->assertCount(3, $pairs);

        foreach ($pairs as $pair) {
            $bands = [$pair->userOne->tenureBand(), $pair->userTwo->tenureBand()];
            sort($bands);
            $this->assertSame(['junior', 'senior'], $bands);
            $this->assertNotEmpty($pair->match_reason);
        }

        $this->assertSame('matched', $round->fresh()->status);
    }

    public function test_it_leaves_the_surplus_side_unmatched_rather_than_breaking_the_rule(): void
    {
        $round = $this->openRound();

        foreach (User::factory()->count(4)->veteran()->create() as $user) {
            $round->signups()->create(['user_id' => $user->id]);
        }
        $junior = User::factory()->create(['joined_at' => now()->subYear()]);
        $round->signups()->create(['user_id' => $junior->id]);

        $pairs = app(BlindMeetupMatcher::class)->match($round);

        $this->assertCount(1, $pairs);
        $this->assertSame(3, $round->signups()->where('status', 'unmatched')->count());
    }

    public function test_a_member_can_sign_up_and_withdraw(): void
    {
        $round = $this->openRound();
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->postJson("/api/v1/meetups/rounds/{$round->id}/signup", ['note' => 'Any office works'])
            ->assertCreated();

        $this->getJson('/api/v1/meetups/rounds/current')
            ->assertOk()
            ->assertJsonPath('data.my_signup.status', 'signed_up');

        $this->deleteJson("/api/v1/meetups/rounds/{$round->id}/signup")->assertOk();
        $this->assertSame('withdrawn', $round->signups()->first()->status);
    }

    public function test_it_refuses_signups_once_the_window_closes(): void
    {
        $round = $this->openRound();
        $round->update(['signups_close_at' => now()->subDay()]);

        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->postJson("/api/v1/meetups/rounds/{$round->id}/signup")->assertStatus(422);
    }

    public function test_only_admins_can_create_rounds_and_run_matching(): void
    {
        $round = $this->openRound();

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/meetups/rounds/{$round->id}/match")->assertStatus(403);

        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $this->postJson("/api/v1/meetups/rounds/{$round->id}/match")->assertOk();
    }

    public function test_the_meetup_lands_on_the_last_friday_of_the_month(): void
    {
        $date = BlindMeetupMatcher::lastFridayOf(2026, 9);

        $this->assertTrue($date->isFriday());
        $this->assertSame('2026-09-25', $date->toDateString());
        $this->assertTrue($date->copy()->addWeek()->month !== 9);
    }
}
