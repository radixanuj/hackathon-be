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
}
