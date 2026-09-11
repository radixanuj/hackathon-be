<?php

namespace Tests\Feature\Api;

use App\Models\BuddyPairing;
use App\Models\BuddySignup;
use App\Models\User;
use App\Services\BuddyMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cross-location Buddy — Connect, Phase 2. */
class BuddyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_pairs_people_from_different_locations(): void
    {
        $mumbai = User::factory()->create(['location' => 'Mumbai', 'team' => 'Engineering']);
        $alsoMumbai = User::factory()->create(['location' => 'Mumbai', 'team' => 'Sales']);

        BuddySignup::create(['user_id' => $mumbai->id]);
        BuddySignup::create(['user_id' => $alsoMumbai->id]);

        $this->assertNull(app(BuddyMatcher::class)->matchFor($mumbai));
        $this->assertSame(0, BuddyPairing::count());

        // A third person elsewhere unblocks it.
        $london = User::factory()->create(['location' => 'London', 'team' => 'Design']);
        BuddySignup::create(['user_id' => $london->id]);

        $pairing = app(BuddyMatcher::class)->matchFor($mumbai);

        $this->assertNotNull($pairing);
        $this->assertSame($london->id, $pairing->partnerFor($mumbai->id)->id);
    }

    public function test_opting_in_pairs_you_immediately_when_someone_is_waiting(): void
    {
        $waiting = User::factory()->create(['location' => 'Dubai']);
        BuddySignup::create(['user_id' => $waiting->id]);

        $me = User::factory()->create(['location' => 'Mumbai']);
        $this->actingAs($me, 'sanctum');

        $this->postJson('/api/v1/me/buddy', ['note' => 'Keen'])
            ->assertCreated()
            ->assertJsonPath('data.signup.status', 'paired')
            ->assertJsonPath('data.pairing.buddy.id', $waiting->id);
    }

    public function test_opting_in_with_nobody_waiting_leaves_you_in_the_pool(): void
    {
        $me = User::factory()->create(['location' => 'Mumbai']);
        $this->actingAs($me, 'sanctum');

        $this->postJson('/api/v1/me/buddy')
            ->assertCreated()
            ->assertJsonPath('data.signup.status', 'waiting')
            ->assertJsonPath('data.pairing', null);
    }

    public function test_it_requires_a_location_on_your_profile(): void
    {
        $me = User::factory()->create(['location' => null]);
        $this->actingAs($me, 'sanctum');

        $this->postJson('/api/v1/me/buddy')->assertStatus(422);
    }

    public function test_it_refuses_a_second_buddy_while_one_is_active(): void
    {
        $other = User::factory()->create(['location' => 'London']);
        BuddySignup::create(['user_id' => $other->id]);

        $me = User::factory()->create(['location' => 'Mumbai']);
        $this->actingAs($me, 'sanctum');

        $this->postJson('/api/v1/me/buddy')->assertCreated();
        $this->postJson('/api/v1/me/buddy')->assertStatus(422);
    }

    public function test_it_does_not_re_pair_the_same_two_people(): void
    {
        $me = User::factory()->create(['location' => 'Mumbai']);
        $other = User::factory()->create(['location' => 'London']);

        BuddySignup::create(['user_id' => $me->id]);
        BuddySignup::create(['user_id' => $other->id]);

        $pairing = app(BuddyMatcher::class)->matchFor($me);
        $this->assertNotNull($pairing);

        $pairing->update(['status' => 'ended', 'ended_at' => now()]);
        BuddySignup::whereIn('user_id', [$me->id, $other->id])->update(['status' => 'waiting']);

        $this->assertNull(app(BuddyMatcher::class)->matchFor($me));
    }

    public function test_either_buddy_can_end_the_pairing_but_nobody_else(): void
    {
        $a = User::factory()->create(['location' => 'Mumbai']);
        $b = User::factory()->create(['location' => 'London']);
        $bystander = User::factory()->create();

        $pairing = BuddyPairing::create([
            'user_one_id' => $a->id, 'user_two_id' => $b->id, 'started_at' => now(),
        ]);

        $this->actingAs($bystander, 'sanctum');
        $this->postJson("/api/v1/buddy-pairings/{$pairing->id}/end")->assertStatus(403);

        $this->actingAs($b, 'sanctum');
        $this->postJson("/api/v1/buddy-pairings/{$pairing->id}/end")
            ->assertOk()->assertJsonPath('data.status', 'ended');
    }

    public function test_the_buddy_shown_is_the_other_person(): void
    {
        $a = User::factory()->create(['location' => 'Mumbai']);
        $b = User::factory()->create(['location' => 'London']);

        BuddyPairing::create([
            'user_one_id' => $a->id, 'user_two_id' => $b->id, 'started_at' => now(),
        ]);

        $this->actingAs($a, 'sanctum');
        $this->getJson('/api/v1/me/buddy')->assertOk()->assertJsonPath('data.pairing.buddy.id', $b->id);

        $this->actingAs($b, 'sanctum');
        $this->getJson('/api/v1/me/buddy')->assertOk()->assertJsonPath('data.pairing.buddy.id', $a->id);
    }
}
