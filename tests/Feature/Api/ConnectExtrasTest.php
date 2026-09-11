<?php

namespace Tests\Feature\Api;

use App\Models\CoffeeInvite;
use App\Models\OfficeHourSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Office Hours and Open Coffee / Lunch Invites — Connect, Phase 2. */
class ConnectExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_slot_can_be_booked_until_it_is_full(): void
    {
        $host = User::factory()->create();
        $slot = OfficeHourSlot::create([
            'host_id' => $host->id,
            'starts_at' => now()->addWeek(),
            'capacity' => 1,
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/office-hours/{$slot->id}/book", ['topic' => 'Queues'])
            ->assertOk()
            ->assertJsonPath('data.bookings_count', 1)
            ->assertJsonPath('data.is_full', true)
            ->assertJsonPath('data.my_booking', 'booked');

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/office-hours/{$slot->id}/book")->assertStatus(422);
    }

    public function test_cancelling_a_booking_frees_the_seat(): void
    {
        $host = User::factory()->create();
        $slot = OfficeHourSlot::create([
            'host_id' => $host->id, 'starts_at' => now()->addWeek(), 'capacity' => 1,
        ]);

        $booker = User::factory()->create();
        $this->actingAs($booker, 'sanctum');
        $this->postJson("/api/v1/office-hours/{$slot->id}/book")->assertOk();
        $this->deleteJson("/api/v1/office-hours/{$slot->id}/book")
            ->assertOk()->assertJsonPath('data.bookings_count', 0);

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/office-hours/{$slot->id}/book")
            ->assertOk()->assertJsonPath('data.bookings_count', 1);
    }

    public function test_a_host_cannot_book_their_own_slot(): void
    {
        $host = User::factory()->create();
        $slot = OfficeHourSlot::create(['host_id' => $host->id, 'starts_at' => now()->addWeek()]);

        $this->actingAs($host, 'sanctum');
        $this->postJson("/api/v1/office-hours/{$slot->id}/book")->assertStatus(422);
    }

    public function test_a_past_slot_cannot_be_booked(): void
    {
        $slot = OfficeHourSlot::create([
            'host_id' => User::factory()->create()->id,
            'starts_at' => now()->subDay(),
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/office-hours/{$slot->id}/book")->assertStatus(422);
    }

    public function test_only_the_host_can_edit_a_slot(): void
    {
        $slot = OfficeHourSlot::create([
            'host_id' => User::factory()->create()->id,
            'starts_at' => now()->addWeek(),
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->patchJson("/api/v1/office-hours/{$slot->id}", ['capacity' => 9])->assertStatus(403);
    }

    public function test_a_coffee_invite_fills_up_and_the_host_is_not_a_guest(): void
    {
        $host = User::factory()->create();
        $this->actingAs($host, 'sanctum');

        $id = $this->postJson('/api/v1/coffee-invites', [
            'kind' => 'lunch',
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'capacity' => 1,
        ])->assertCreated()->json('data.id');

        // The host does not occupy one of the seats on offer.
        $this->assertSame(0, CoffeeInvite::find($id)->joins_count);
        $this->postJson("/api/v1/coffee-invites/{$id}/join")->assertStatus(422);

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/coffee-invites/{$id}/join")
            ->assertOk()->assertJsonPath('data.is_full', true)->assertJsonPath('data.has_joined', true);

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/coffee-invites/{$id}/join")->assertStatus(422);
    }

    public function test_leaving_an_invite_frees_a_seat(): void
    {
        $invite = CoffeeInvite::create([
            'host_id' => User::factory()->create()->id,
            'kind' => 'coffee',
            'starts_at' => now()->addDay(),
            'capacity' => 1,
        ]);

        $guest = User::factory()->create();
        $this->actingAs($guest, 'sanctum');
        $this->postJson("/api/v1/coffee-invites/{$invite->id}/join")->assertOk();
        $this->deleteJson("/api/v1/coffee-invites/{$invite->id}/join")
            ->assertOk()->assertJsonPath('data.joins_count', 0);
    }

    public function test_a_cancelled_invite_cannot_be_joined(): void
    {
        $invite = CoffeeInvite::create([
            'host_id' => User::factory()->create()->id,
            'kind' => 'coffee',
            'starts_at' => now()->addDay(),
            'status' => 'cancelled',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/coffee-invites/{$invite->id}/join")->assertStatus(422);
    }
}
