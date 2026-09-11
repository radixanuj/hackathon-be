<?php

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Models\Nudge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** People — Nudges. The poke, and the one rule that keeps it a conversation. */
class NudgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_nudge_notifies_the_person_nudged(): void
    {
        $sender = User::factory()->create(['name' => 'Priya Nair']);
        $recipient = User::factory()->create();

        $this->actingAs($sender, 'sanctum');
        $this->postJson("/api/v1/users/{$recipient->id}/nudge")
            ->assertCreated()
            ->assertJsonPath('data.streak', 1)
            ->assertJsonPath('data.direction', 'sent')
            ->assertJsonPath('data.waiting_on_them', true);

        $notification = Notification::where('user_id', $recipient->id)->sole();
        $this->assertSame('nudge.received', $notification->type);
        $this->assertSame('people', $notification->category);
        $this->assertSame($sender->id, $notification->actor_id);
        $this->assertSame('Priya Nair nudged you', $notification->title);
        $this->assertSame('/people?profile='.$sender->id, $notification->action_url);

        // The sender hears nothing about their own tap.
        $this->assertSame(0, Notification::where('user_id', $sender->id)->count());
    }

    public function test_you_cannot_nudge_the_same_person_twice_in_a_row(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create(['name' => 'Sam Okafor']);

        $this->actingAs($sender, 'sanctum');
        $this->postJson("/api/v1/users/{$recipient->id}/nudge")->assertCreated();

        $this->postJson("/api/v1/users/{$recipient->id}/nudge")
            ->assertStatus(422)
            ->assertJsonPath('message', 'You have already nudged Sam. It is their turn now.');

        $this->assertSame(1, Nudge::count());
        $this->assertSame(1, Notification::where('user_id', $recipient->id)->count());
    }

    public function test_nudging_back_reopens_the_exchange_and_deepens_the_streak(): void
    {
        $one = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($one, 'sanctum');
        $this->postJson("/api/v1/users/{$other->id}/nudge")->assertCreated();

        // Their turn: the same endpoint, and it counts as the reply.
        $this->actingAs($other, 'sanctum');
        $this->postJson("/api/v1/users/{$one->id}/nudge")
            ->assertCreated()
            ->assertJsonPath('data.streak', 2);

        $first = Nudge::orderBy('id')->first();
        $this->assertNotNull($first->returned_at, 'Answering a nudge should close it out.');

        // With the ball back in their court, the first person may nudge again.
        $this->actingAs($one, 'sanctum');
        $this->postJson("/api/v1/users/{$other->id}/nudge")
            ->assertCreated()
            ->assertJsonPath('data.streak', 3);
    }

    public function test_you_cannot_nudge_yourself_or_a_deactivated_colleague(): void
    {
        $user = User::factory()->create();
        $gone = User::factory()->create(['is_active' => false]);

        $this->actingAs($user, 'sanctum');
        $this->postJson("/api/v1/users/{$user->id}/nudge")->assertStatus(422);
        $this->postJson("/api/v1/users/{$gone->id}/nudge")->assertStatus(422);

        $this->assertSame(0, Nudge::count());
    }

    public function test_a_profile_carries_where_the_two_of_you_stand(): void
    {
        $viewer = User::factory()->create();
        $person = User::factory()->create();

        $this->actingAs($viewer, 'sanctum');
        $this->getJson("/api/v1/users/{$person->id}")
            ->assertOk()
            ->assertJsonPath('data.nudge.can_nudge', true)
            ->assertJsonPath('data.nudge.streak', 0);

        $this->postJson("/api/v1/users/{$person->id}/nudge")->assertCreated();

        $this->getJson("/api/v1/users/{$person->id}")
            ->assertJsonPath('data.nudge.can_nudge', false)
            ->assertJsonPath('data.nudge.waiting_on_them', true);

        // From the other side it is their turn, and nudging is nudging back.
        $this->actingAs($person, 'sanctum');
        $this->getJson("/api/v1/users/{$viewer->id}")
            ->assertJsonPath('data.nudge.can_nudge', true)
            ->assertJsonPath('data.nudge.waiting_on_you', true)
            ->assertJsonPath('data.nudge.streak', 1);
    }

    public function test_the_list_covers_both_directions_and_counts_whose_turn_it_is(): void
    {
        $me = User::factory()->create();
        $nudgedMe = User::factory()->create();
        $iNudged = User::factory()->create();

        $this->actingAs($nudgedMe, 'sanctum');
        $this->postJson("/api/v1/users/{$me->id}/nudge")->assertCreated();

        $this->actingAs($me, 'sanctum');
        $this->postJson("/api/v1/users/{$iNudged->id}/nudge")->assertCreated();

        $list = $this->getJson('/api/v1/me/nudges')->assertOk();
        $list->assertJsonCount(2, 'data');
        $list->assertJsonPath('summary.waiting_on_you', 1);
        $list->assertJsonPath('summary.waiting_on_them', 1);

        // Every row names the other person, whichever way it went.
        $received = $this->getJson('/api/v1/me/nudges?scope=received&status=outstanding')->assertOk();
        $received->assertJsonCount(1, 'data');
        $received->assertJsonPath('data.0.direction', 'received');
        $received->assertJsonPath('data.0.person.id', $nudgedMe->id);
        $received->assertJsonPath('data.0.waiting_on_you', true);

        $this->getJson('/api/v1/me/nudges/summary')
            ->assertOk()
            ->assertJsonPath('data.waiting_on_you', 1);
    }

    public function test_one_persons_nudge_does_not_lock_anybody_elses(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $someoneElse = User::factory()->create();

        $this->actingAs($sender, 'sanctum');
        $this->postJson("/api/v1/users/{$recipient->id}/nudge")->assertCreated();
        $this->postJson("/api/v1/users/{$someoneElse->id}/nudge")->assertCreated();

        // And the recipient is free to nudge a third party, not just back.
        $this->actingAs($recipient, 'sanctum');
        $this->postJson("/api/v1/users/{$someoneElse->id}/nudge")->assertCreated();

        $this->assertSame(3, Nudge::count());
    }
}
