<?php

namespace Tests\Feature\Api;

use App\Models\SessionRequest;
use App\Models\Tag;
use App\Models\User;
use App\Services\ConnectionSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Who Should I Meet? — People, Phase 2. */
class SuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ranks_complementary_knowledge_above_a_stranger(): void
    {
        $kubernetes = Tag::findOrCreateByName('Kubernetes', 'skill');

        $me = User::factory()->create(['team' => 'Product', 'location' => 'Mumbai']);
        $me->tags()->attach($kubernetes->id, ['kind' => 'want_to_learn']);

        $expert = User::factory()->create(['team' => 'Engineering', 'location' => 'Pune']);
        $expert->tags()->attach($kubernetes->id, ['kind' => 'can_help_with']);

        User::factory()->count(3)->create(['team' => 'Sales', 'location' => 'Mumbai']);

        $this->actingAs($me, 'sanctum');
        $response = $this->getJson('/api/v1/me/suggestions?limit=5')->assertOk();

        $this->assertSame($expert->id, $response->json('data.0.person.id'));
        $this->assertStringContainsString('Kubernetes', implode(' ', $response->json('data.0.reasons')));
    }

    public function test_shared_interests_are_a_reason(): void
    {
        $f1 = Tag::findOrCreateByName('F1', 'interest');

        $me = User::factory()->create();
        $me->tags()->attach($f1->id, ['kind' => 'interest']);

        $fan = User::factory()->create();
        $fan->tags()->attach($f1->id, ['kind' => 'interest']);

        User::factory()->count(2)->create();

        $this->actingAs($me, 'sanctum');
        $reasons = collect($this->getJson('/api/v1/me/suggestions')->json('data'))
            ->firstWhere('person.id', $fan->id)['reasons'];

        $this->assertStringContainsString('F1', implode(' ', $reasons));
    }

    public function test_someone_you_have_already_connected_with_ranks_below_a_stranger(): void
    {
        $me = User::factory()->create(['team' => 'Product', 'location' => 'Mumbai']);
        $known = User::factory()->create(['team' => 'Engineering', 'location' => 'Pune']);
        $stranger = User::factory()->create(['team' => 'Engineering', 'location' => 'Pune']);

        SessionRequest::create([
            'requester_id' => $me->id,
            'recipient_id' => $known->id,
            'topic' => 'Anything',
            'category' => 'career',
        ]);

        $this->actingAs($me, 'sanctum');
        $data = collect($this->getJson('/api/v1/me/suggestions')->json('data'));

        $knownEntry = $data->firstWhere('person.id', $known->id);
        $strangerEntry = $data->firstWhere('person.id', $stranger->id);

        $this->assertTrue($knownEntry['previously_connected']);
        $this->assertFalse($strangerEntry['previously_connected']);
        $this->assertSame($stranger->id, $data->first()['person']['id']);
    }

    public function test_previous_interaction_is_detected_in_both_directions(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        SessionRequest::create([
            'requester_id' => $other->id,
            'recipient_id' => $me->id,
            'topic' => 'Anything',
            'category' => 'career',
        ]);

        $ids = app(ConnectionSuggester::class)->previouslyConnectedIds($me);

        $this->assertContains($other->id, $ids);
        $this->assertNotContains($me->id, $ids);
    }

    public function test_a_dismissed_person_stops_being_suggested(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($me, 'sanctum');
        $this->assertCount(1, $this->getJson('/api/v1/me/suggestions')->json('data'));

        $this->postJson("/api/v1/users/{$other->id}/dismiss-suggestion")->assertOk();
        $this->assertCount(0, $this->getJson('/api/v1/me/suggestions')->json('data'));

        $this->deleteJson("/api/v1/users/{$other->id}/dismiss-suggestion")->assertOk();
        $this->assertCount(1, $this->getJson('/api/v1/me/suggestions')->json('data'));
    }

    public function test_it_never_suggests_yourself(): void
    {
        $me = User::factory()->create();
        User::factory()->count(4)->create();

        $this->actingAs($me, 'sanctum');
        $ids = collect($this->getJson('/api/v1/me/suggestions?limit=10')->json('data'))
            ->pluck('person.id');

        $this->assertNotContains($me->id, $ids);
        $this->postJson("/api/v1/users/{$me->id}/dismiss-suggestion")->assertStatus(422);
    }
}
