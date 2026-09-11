<?php

namespace Tests\Feature\Api;

use App\Models\Ama;
use App\Models\Event;
use App\Models\InterestGroup;
use App\Models\Recommendation;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Interest Groups, Recommendations, AMAs, Events and Stories. */
class CommunityContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_group_makes_you_its_owner_and_first_member(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/groups', [
            'name' => 'F1 Sundays',
            'category' => 'sports',
            'external_platform' => 'whatsapp',
            'external_link' => 'https://example.com/f1',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'f1-sundays')
            ->assertJsonPath('data.members_count', 1)
            ->assertJsonPath('data.is_member', true)
            ->assertJsonPath('data.my_role', 'owner');
    }

    public function test_members_can_join_and_leave_and_non_owners_cannot_edit(): void
    {
        $owner = User::factory()->create();
        $joiner = User::factory()->create();

        $group = InterestGroup::create([
            'name' => 'Book Club', 'slug' => 'book-club', 'category' => 'books', 'created_by' => $owner->id,
        ]);
        $group->members()->attach($owner->id, ['role' => 'owner']);
        $group->syncMembersCount();

        $this->actingAs($joiner, 'sanctum');
        $this->postJson('/api/v1/groups/book-club/join')->assertOk()->assertJsonPath('data.members_count', 2);
        $this->patchJson('/api/v1/groups/book-club', ['description' => 'hijack'])->assertStatus(403);
        $this->deleteJson('/api/v1/groups/book-club/join')->assertOk()->assertJsonPath('data.members_count', 1);
    }

    public function test_recommendations_require_a_why_and_can_be_liked_once(): void
    {
        $author = User::factory()->create();
        $reader = User::factory()->create();

        $this->actingAs($author, 'sanctum');
        $this->postJson('/api/v1/recommendations', [
            'title' => 'Thinking in Systems', 'type' => 'book', 'stream' => 'work',
        ])->assertStatus(422)->assertJsonValidationErrors('why');

        $id = $this->postJson('/api/v1/recommendations', [
            'title' => 'Thinking in Systems', 'type' => 'book', 'stream' => 'work',
            'why' => 'Changed how I look at feedback loops.',
        ])->assertCreated()->json('data.id');

        $this->actingAs($reader, 'sanctum');
        $this->postJson("/api/v1/recommendations/{$id}/like")->assertOk()->assertJsonPath('data.likes_count', 1);
        $this->postJson("/api/v1/recommendations/{$id}/like")->assertOk()->assertJsonPath('data.likes_count', 1);
        $this->deleteJson("/api/v1/recommendations/{$id}/like")->assertOk()->assertJsonPath('data.likes_count', 0);

        $this->deleteJson("/api/v1/recommendations/{$id}")->assertStatus(403);
    }

    public function test_only_the_ama_host_can_answer_questions(): void
    {
        $host = User::factory()->create();
        $asker = User::factory()->create();

        $ama = Ama::create(['host_id' => $host->id, 'title' => 'Ten years at Radix', 'format' => 'async', 'status' => 'open']);

        $this->actingAs($asker, 'sanctum');
        $questionId = $this->postJson("/api/v1/amas/{$ama->id}/questions", ['body' => 'What changed most?'])
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/ama-questions/{$questionId}/answers", ['body' => 'Not my AMA'])
            ->assertStatus(403);

        $this->postJson("/api/v1/ama-questions/{$questionId}/upvote")->assertOk()
            ->assertJsonPath('data.upvotes_count', 1)
            ->assertJsonPath('data.is_upvoted', true);

        $this->actingAs($host, 'sanctum');
        $this->postJson("/api/v1/ama-questions/{$questionId}/answers", ['body' => 'The people, mostly.'])
            ->assertCreated();
    }

    public function test_a_closed_ama_stops_taking_questions(): void
    {
        $ama = Ama::create([
            'host_id' => User::factory()->create()->id,
            'title' => 'Closed', 'format' => 'async', 'status' => 'closed',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/amas/{$ama->id}/questions", ['body' => 'Too late?'])->assertStatus(422);
    }

    public function test_events_rsvp_the_host_automatically_and_respect_capacity(): void
    {
        $host = User::factory()->create();
        $this->actingAs($host, 'sanctum');

        $id = $this->postJson('/api/v1/events', [
            'title' => 'Sunday run', 'category' => 'sports',
            'starts_at' => now()->addWeek()->toIso8601String(), 'capacity' => 2,
        ])->assertCreated()->json('data.id');

        $this->assertSame(1, Event::find($id)->going_count);

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/events/{$id}/rsvp", ['status' => 'going'])
            ->assertOk()->assertJsonPath('data.going_count', 2)->assertJsonPath('data.is_full', true);

        // Third person is turned away, but can still say maybe.
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/events/{$id}/rsvp", ['status' => 'going'])->assertStatus(422);
        $this->postJson("/api/v1/events/{$id}/rsvp", ['status' => 'maybe'])
            ->assertOk()->assertJsonPath('data.my_rsvp', 'maybe');
    }

    public function test_upcoming_scope_excludes_past_events(): void
    {
        $host = User::factory()->create();
        Event::create(['host_id' => $host->id, 'title' => 'Past', 'category' => 'work', 'starts_at' => now()->subWeek()]);
        Event::create(['host_id' => $host->id, 'title' => 'Future', 'category' => 'work', 'starts_at' => now()->addWeek()]);

        $this->actingAs($host, 'sanctum');
        $this->getJson('/api/v1/events?scope=upcoming')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Future');
        $this->getJson('/api/v1/events?scope=past')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Past');
    }

    public function test_a_story_reaction_is_one_per_person_and_replaces_itself(): void
    {
        $story = Story::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Completed HYROX', 'body' => 'Signed up on a dare.', 'category' => 'sport',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->postJson("/api/v1/stories/{$story->id}/react", ['reaction' => 'clap'])
            ->assertOk()->assertJsonPath('data.reactions_count', 1);
        $this->postJson("/api/v1/stories/{$story->id}/react", ['reaction' => 'inspired'])
            ->assertOk()
            ->assertJsonPath('data.reactions_count', 1)
            ->assertJsonPath('data.my_reaction', 'inspired');
        $this->deleteJson("/api/v1/stories/{$story->id}/react")
            ->assertOk()->assertJsonPath('data.reactions_count', 0);
    }

    public function test_a_story_becomes_an_ama_exactly_once(): void
    {
        $author = User::factory()->create();
        $story = Story::create([
            'user_id' => $author->id, 'title' => 'Three weeks in Japan',
            'body' => 'Solo, mostly by train.', 'category' => 'travel',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/stories/{$story->id}/convert-to-ama")->assertStatus(403);

        $this->actingAs($author, 'sanctum');
        $amaId = $this->postJson("/api/v1/stories/{$story->id}/convert-to-ama")
            ->assertCreated()->json('data.id');

        $this->assertSame($amaId, $story->fresh()->ama_id);
        $this->assertSame($story->id, Ama::find($amaId)->story_id);

        $this->postJson("/api/v1/stories/{$story->id}/convert-to-ama")->assertStatus(422);
    }

    public function test_the_dashboard_returns_every_pillar(): void
    {
        $user = User::factory()->create();
        Recommendation::create(['user_id' => $user->id, 'title' => 'A book', 'type' => 'book', 'stream' => 'work', 'why' => 'Good.']);

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonStructure([
            'data' => [
                'quest', 'blind_meetup_round', 'pending_session_requests',
                'upcoming_events', 'open_amas', 'latest_recommendations', 'latest_stories',
            ],
        ]);
    }
}
