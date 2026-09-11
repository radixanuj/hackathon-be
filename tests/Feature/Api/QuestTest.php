<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestTest extends TestCase
{
    use RefreshDatabase;

    public function test_quest_suggestions_cross_teams_and_carry_a_reason(): void
    {
        foreach (['Engineering', 'Design', 'Sales', 'Finance', 'People', 'Data'] as $team) {
            User::factory()->count(2)->create(['team' => $team]);
        }

        $joiner = User::factory()->newJoiner()->create(['team' => 'Engineering']);
        $this->actingAs($joiner, 'sanctum');

        $response = $this->getJson('/api/v1/me/quest')->assertOk();

        $targets = $response->json('data.targets');
        $this->assertCount(5, $targets);

        foreach ($targets as $target) {
            $this->assertNotEmpty($target['reason']);
            $this->assertNotSame($joiner->id, $target['person']['id']);
        }

        // One person per team while distinct teams are available.
        $teams = array_column(array_column($targets, 'person'), 'team');
        $this->assertSame(count($teams), count(array_unique($teams)));
    }

    public function test_it_completes_the_quest_once_nothing_is_pending(): void
    {
        User::factory()->count(8)->create();
        $joiner = User::factory()->newJoiner()->create();
        $this->actingAs($joiner, 'sanctum');

        $targets = $this->getJson('/api/v1/me/quest')->json('data.targets');

        foreach ($targets as $target) {
            $this->patchJson("/api/v1/quest-targets/{$target['id']}", ['status' => 'met'])->assertOk();
        }

        $this->assertSame('completed', $joiner->fresh()->quest->status);
    }

    public function test_it_refuses_to_update_someone_elses_quest_target(): void
    {
        User::factory()->count(8)->create();
        $owner = User::factory()->newJoiner()->create();
        $intruder = User::factory()->create();

        $this->actingAs($owner, 'sanctum');
        $targetId = $this->getJson('/api/v1/me/quest')->json('data.targets.0.id');

        $this->actingAs($intruder, 'sanctum');
        $this->patchJson("/api/v1/quest-targets/{$targetId}", ['status' => 'met'])->assertStatus(403);
    }
}
