<?php

namespace Tests\Feature\Api;

use App\Models\SessionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_recipient_can_accept_decline_or_suggest_another_time(): void
    {
        $requester = User::factory()->create();
        $mentor = User::factory()->create(['open_to_mentoring' => true]);

        $this->actingAs($requester, 'sanctum');
        $id = $this->postJson('/api/v1/session-requests', [
            'recipient_id' => $mentor->id,
            'topic' => 'Getting started with BigQuery ML',
            'category' => 'technical',
        ])->assertCreated()->json('data.id');

        $this->actingAs($mentor, 'sanctum');

        $this->postJson("/api/v1/session-requests/{$id}/respond", [
            'action' => 'suggest_time',
            'scheduled_at' => now()->addWeek()->toIso8601String(),
            'response_message' => 'Thursday works better.',
        ])->assertOk()->assertJsonPath('data.status', 'time_suggested');

        $this->postJson("/api/v1/session-requests/{$id}/respond", [
            'action' => 'accept',
            'scheduled_at' => now()->addWeek()->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.status', 'accepted');
    }

    public function test_only_the_recipient_may_respond(): void
    {
        $requester = User::factory()->create();
        $mentor = User::factory()->create();
        $bystander = User::factory()->create();

        $request = SessionRequest::create([
            'requester_id' => $requester->id,
            'recipient_id' => $mentor->id,
            'topic' => 'Career paths',
            'category' => 'career',
        ]);

        foreach ([$requester, $bystander] as $user) {
            $this->actingAs($user, 'sanctum');
            $this->postJson("/api/v1/session-requests/{$request->id}/respond", ['action' => 'decline'])
                ->assertStatus(403);
        }
    }

    public function test_it_refuses_requests_to_someone_not_open_to_mentoring(): void
    {
        $mentor = User::factory()->create(['open_to_mentoring' => false]);
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->postJson('/api/v1/session-requests', [
            'recipient_id' => $mentor->id,
            'topic' => 'Anything',
            'category' => 'career',
        ])->assertStatus(422);
    }

    public function test_it_refuses_a_request_to_yourself(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/session-requests', [
            'recipient_id' => $user->id,
            'topic' => 'Talking to myself',
            'category' => 'career',
        ])->assertStatus(422);
    }

    public function test_only_the_requester_can_cancel(): void
    {
        $requester = User::factory()->create();
        $mentor = User::factory()->create();

        $request = SessionRequest::create([
            'requester_id' => $requester->id,
            'recipient_id' => $mentor->id,
            'topic' => 'Leadership',
            'category' => 'leadership',
        ]);

        $this->actingAs($mentor, 'sanctum');
        $this->patchJson("/api/v1/session-requests/{$request->id}", ['status' => 'cancelled'])
            ->assertStatus(403);

        // ...but either side may mark it done.
        $this->patchJson("/api/v1/session-requests/{$request->id}", ['status' => 'completed'])->assertOk();
    }

    public function test_it_separates_incoming_from_outgoing(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        SessionRequest::create(['requester_id' => $user->id, 'recipient_id' => $other->id,
            'topic' => 'Out', 'category' => 'career']);
        SessionRequest::create(['requester_id' => $other->id, 'recipient_id' => $user->id,
            'topic' => 'In', 'category' => 'career']);

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/session-requests?direction=incoming')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.topic', 'In');
        $this->getJson('/api/v1/session-requests?direction=outgoing')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.topic', 'Out');
        $this->getJson('/api/v1/session-requests?direction=all')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_request_carries_the_kind_it_was_asked_through(): void
    {
        $requester = User::factory()->create();
        $coach = User::factory()->create(['open_to_mentoring' => true]);

        $this->actingAs($requester, 'sanctum');

        $this->postJson('/api/v1/session-requests', [
            'recipient_id' => $coach->id,
            'kind' => 'coaching',
            'topic' => 'Leading a team',
            'category' => 'leadership',
            'duration_minutes' => 45,
            'proposed_at' => now()->addDays(2)->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'coaching')
            ->assertJsonPath('data.duration_minutes', 45)
            ->assertJsonPath('data.status', 'pending');

        // Left off, it is a one-off knowledge session.
        $this->postJson('/api/v1/session-requests', [
            'recipient_id' => $coach->id,
            'topic' => 'BigQuery',
            'category' => 'technical',
        ])->assertCreated()->assertJsonPath('data.kind', 'knowledge');

        $this->postJson('/api/v1/session-requests', [
            'recipient_id' => $coach->id,
            'kind' => 'something-else',
            'topic' => 'BigQuery',
            'category' => 'technical',
        ])->assertStatus(422);
    }

    public function test_it_filters_the_list_by_kind(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        SessionRequest::create(['requester_id' => $user->id, 'recipient_id' => $other->id,
            'kind' => 'mentoring', 'topic' => 'Career direction', 'category' => 'career']);
        SessionRequest::create(['requester_id' => $user->id, 'recipient_id' => $other->id,
            'kind' => 'knowledge', 'topic' => 'BigQuery', 'category' => 'technical']);

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/session-requests?direction=outgoing&kind=mentoring')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.topic', 'Career direction');
        $this->getJson('/api/v1/session-requests?direction=outgoing')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_mentoring_ask_only_reaches_the_mentoring_roster(): void
    {
        $requester = User::factory()->create();
        // Open to being asked, but not on the roster: fine for a knowledge
        // session, not for a standing mentoring relationship.
        $willing = User::factory()->create(['open_to_mentoring' => true, 'is_mentor' => false]);
        $mentor = User::factory()->create(['open_to_mentoring' => true, 'is_mentor' => true]);

        $this->actingAs($requester, 'sanctum');

        $this->postJson('/api/v1/session-requests', [
            'recipient_id' => $willing->id,
            'kind' => 'mentoring',
            'topic' => 'Career direction',
            'category' => 'career',
        ])->assertStatus(422);

        $this->postJson('/api/v1/session-requests', [
            'recipient_id' => $willing->id,
            'kind' => 'knowledge',
            'topic' => 'BigQuery',
            'category' => 'technical',
        ])->assertCreated();

        $this->postJson('/api/v1/session-requests', [
            'recipient_id' => $mentor->id,
            'kind' => 'mentoring',
            'topic' => 'Career direction',
            'category' => 'career',
        ])->assertCreated()->assertJsonPath('data.kind', 'mentoring');
    }

    public function test_the_people_list_can_be_narrowed_to_the_mentoring_roster(): void
    {
        $mentor = User::factory()->create(['name' => 'Mentor One', 'is_mentor' => true]);
        User::factory()->create(['name' => 'Willing Two', 'open_to_mentoring' => true, 'is_mentor' => false]);

        $this->actingAs($mentor, 'sanctum');

        $this->getJson('/api/v1/users?mentors=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mentor One')
            ->assertJsonPath('data.0.is_mentor', true);

        // Without the filter, both are still there.
        $this->getJson('/api/v1/users')->assertOk()->assertJsonCount(2, 'data');
    }
}
