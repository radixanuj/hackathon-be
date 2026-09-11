<?php

namespace Tests\Feature\Api;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_a_user_and_builds_their_quest(): void
    {
        User::factory()->count(8)->create();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Priya Nair',
            'email' => 'priya@radix.email',
            'password' => 'password123',
            'team' => 'Product',
        ]);

        $response->assertCreated()->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email']]]);

        $user = User::where('email', 'priya@radix.email')->first();
        $this->assertNotNull($user->quest);
        $this->assertCount(5, $user->quest->targets);
    }

    public function test_it_rejects_bad_credentials(): void
    {
        User::factory()->create(['email' => 'someone@radix.email']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'someone@radix.email',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/v1/users')->assertStatus(401);
    }

    public function test_it_searches_people_by_skill_team_and_tenure(): void
    {
        $bigQuery = Tag::findOrCreateByName('BigQuery', 'skill');

        $expert = User::factory()->create(['team' => 'Data', 'joined_at' => now()->subYears(8)]);
        $expert->tags()->attach($bigQuery->id, ['kind' => 'can_help_with']);

        User::factory()->create(['team' => 'Sales', 'joined_at' => now()->subYear()]);

        $viewer = User::factory()->create(['team' => 'Support', 'joined_at' => now()->subYear()]);
        $this->actingAs($viewer, 'sanctum');

        $this->getJson('/api/v1/users?skill=bigquery')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expert->id);

        $this->getJson('/api/v1/users?team=Data')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/users?tenure_band=senior')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_it_replaces_the_currently_into_list_and_resolves_icons(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/v1/me/currently', ['currently' => [
            ['label' => 'Reading', 'value' => 'Project Hail Mary'],
            ['label' => 'Training for', 'value' => 'A 10k in November'],
            // Half-filled lines are dropped rather than stored.
            ['label' => 'Watching', 'value' => '  '],
        ]])
            ->assertOk()
            ->assertJsonCount(2, 'data.currently')
            ->assertJsonPath('data.currently.0.icon', '📖')
            ->assertJsonPath('data.currently.1.value', 'A 10k in November');

        // Sent whole: a shorter list is a deletion, not a merge.
        $this->putJson('/api/v1/me/currently', ['currently' => [
            ['icon' => '🎧', 'label' => 'Listening to', 'value' => 'Acquired, on repeat'],
        ]])
            ->assertOk()
            ->assertJsonCount(1, 'data.currently')
            ->assertJsonPath('data.currently.0.icon', '🎧');

        $this->putJson('/api/v1/me/currently', ['currently' => array_fill(0, 5, ['label' => 'Reading', 'value' => 'Too much'])])
            ->assertStatus(422);
    }

    public function test_it_replaces_only_the_tag_section_being_sent(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/v1/me/tags', ['kind' => 'can_talk_about', 'tags' => ['Hiring', 'SEO']])
            ->assertOk();
        $this->putJson('/api/v1/me/tags', ['kind' => 'interest', 'tags' => ['F1']])
            ->assertOk()
            ->assertJsonCount(2, 'data.can_talk_about')
            ->assertJsonCount(1, 'data.interests');

        // Re-sending one section leaves the others untouched.
        $this->putJson('/api/v1/me/tags', ['kind' => 'can_talk_about', 'tags' => ['Hiring']])
            ->assertOk()
            ->assertJsonCount(1, 'data.can_talk_about')
            ->assertJsonCount(1, 'data.interests');
    }
}
