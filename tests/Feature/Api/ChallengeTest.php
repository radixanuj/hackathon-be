<?php

namespace Tests\Feature\Api;

use App\Models\Challenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Challenges — Communities, Phase 2. */
class ChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected function runningChallenge(User $creator): Challenge
    {
        return Challenge::create([
            'created_by' => $creator->id,
            'title' => 'October Running Challenge',
            'slug' => 'october-running-challenge',
            'category' => 'running',
            'unit' => 'km',
            'goal_value' => 100,
            'starts_on' => now()->subWeek(),
            'ends_on' => now()->addWeek(),
        ]);
    }

    public function test_creating_a_challenge_enrols_you(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->postJson('/api/v1/challenges', [
            'title' => 'Read Three Books',
            'category' => 'reading',
            'unit' => 'books',
            'goal_value' => 3,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'read-three-books')
            ->assertJsonPath('data.participants_count', 1)
            ->assertJsonPath('data.my_participation.total_value', 0);
    }

    public function test_logs_add_up_and_rank_the_leaderboard(): void
    {
        $creator = User::factory()->create();
        $challenge = $this->runningChallenge($creator);

        $runner = User::factory()->create();
        $challenge->participants()->create(['user_id' => $creator->id]);
        $challenge->participants()->create(['user_id' => $runner->id]);
        $challenge->syncParticipantsCount();

        $this->actingAs($creator, 'sanctum');
        $this->postJson('/api/v1/challenges/october-running-challenge/logs', ['value' => 5])->assertCreated();
        $this->postJson('/api/v1/challenges/october-running-challenge/logs', ['value' => 7])
            ->assertCreated()->assertJsonPath('data.total_value', 12);

        $this->actingAs($runner, 'sanctum');
        $this->postJson('/api/v1/challenges/october-running-challenge/logs', ['value' => 20])->assertCreated();

        $board = $this->getJson('/api/v1/challenges/october-running-challenge/leaderboard')
            ->assertOk()->json('data');

        $this->assertSame($runner->id, $board[0]['user']['id']);
        $this->assertSame(1, $board[0]['rank']);
        $this->assertSame(20, $board[0]['total_value']);
        $this->assertSame(2, $board[1]['rank']);
        $this->assertSame(12, $board[1]['total_value']);
    }

    public function test_you_must_join_before_logging(): void
    {
        $challenge = $this->runningChallenge(User::factory()->create());

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/v1/challenges/october-running-challenge/logs', ['value' => 5])
            ->assertStatus(422);

        $this->postJson('/api/v1/challenges/october-running-challenge/join')->assertOk();
        $this->postJson('/api/v1/challenges/october-running-challenge/logs', ['value' => 5])
            ->assertCreated();
    }

    public function test_you_cannot_log_against_a_challenge_that_is_not_running(): void
    {
        $creator = User::factory()->create();
        $challenge = $this->runningChallenge($creator);
        $challenge->update(['ends_on' => now()->subDay()]);
        $challenge->participants()->create(['user_id' => $creator->id]);

        $this->actingAs($creator, 'sanctum');
        $this->postJson('/api/v1/challenges/october-running-challenge/logs', ['value' => 5])
            ->assertStatus(422);
    }

    public function test_leaving_drops_you_off_the_leaderboard(): void
    {
        $creator = User::factory()->create();
        $challenge = $this->runningChallenge($creator);
        $challenge->participants()->create(['user_id' => $creator->id]);
        $challenge->syncParticipantsCount();

        $this->actingAs($creator, 'sanctum');
        $this->deleteJson('/api/v1/challenges/october-running-challenge/join')
            ->assertOk()->assertJsonPath('data.participants_count', 0);

        $this->assertCount(0, $this->getJson('/api/v1/challenges/october-running-challenge/leaderboard')->json('data'));
    }

    public function test_only_the_creator_can_edit_it(): void
    {
        $this->runningChallenge(User::factory()->create());

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->patchJson('/api/v1/challenges/october-running-challenge', ['title' => 'Mine now'])
            ->assertStatus(403);
    }

    public function test_the_active_scope_excludes_finished_challenges(): void
    {
        $creator = User::factory()->create();
        $this->runningChallenge($creator);

        Challenge::create([
            'created_by' => $creator->id, 'title' => 'Old One', 'slug' => 'old-one',
            'category' => 'reading', 'unit' => 'books',
            'starts_on' => now()->subMonths(2), 'ends_on' => now()->subMonth(),
        ]);

        $this->actingAs($creator, 'sanctum');
        $this->getJson('/api/v1/challenges?scope=active')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'october-running-challenge');
    }

    public function test_duplicate_titles_get_distinct_slugs(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');

        $payload = [
            'title' => 'Photo a Day', 'category' => 'photography', 'unit' => 'photos',
            'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(),
        ];

        $this->postJson('/api/v1/challenges', $payload)->assertCreated()
            ->assertJsonPath('data.slug', 'photo-a-day');
        $this->postJson('/api/v1/challenges', $payload)->assertCreated()
            ->assertJsonPath('data.slug', 'photo-a-day-2');
    }
}
