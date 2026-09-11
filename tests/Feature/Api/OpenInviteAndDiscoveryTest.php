<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\OpenInvite;
use App\Models\Story;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Open Invites (Do Together) and story discovery (Celebrate & Discover) — Phase 2. */
class OpenInviteAndDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_floating_an_idea_counts_as_being_interested(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->postJson('/api/v1/open-invites', [
            'title' => 'Anyone up for a trek?',
            'category' => 'outdoors',
            'rough_timing' => 'Some weekend in October',
        ])->assertCreated()
            ->assertJsonPath('data.interested_count', 1)
            ->assertJsonPath('data.status', 'open');
    }

    public function test_interest_accumulates_and_converts_into_an_event(): void
    {
        $author = User::factory()->create();
        $this->actingAs($author, 'sanctum');

        $id = $this->postJson('/api/v1/open-invites', [
            'title' => 'Badminton, weekday evenings?',
            'category' => 'sports',
        ])->assertCreated()->json('data.id');

        foreach (User::factory()->count(3)->create() as $user) {
            $this->actingAs($user, 'sanctum');
            $this->postJson("/api/v1/open-invites/{$id}/interest")
                ->assertOk()->assertJsonPath('data.im_interested', true);
        }

        $this->actingAs($author, 'sanctum');
        $eventId = $this->postJson("/api/v1/open-invites/{$id}/convert-to-event", [
            'starts_at' => now()->addWeek()->toIso8601String(),
            'location' => 'Pune',
            'capacity' => 12,
        ])->assertCreated()->json('data.id');

        // The author plus the three who said yes are all RSVP'd going.
        $event = Event::find($eventId);
        $this->assertSame(4, $event->going_count);
        $this->assertSame('sports', $event->category);

        $invite = OpenInvite::find($id);
        $this->assertSame('converted', $invite->status);
        $this->assertSame($eventId, $invite->event_id);

        $this->postJson("/api/v1/open-invites/{$id}/convert-to-event", [
            'starts_at' => now()->addWeeks(2)->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_withdrawing_interest_decrements_the_count(): void
    {
        $invite = OpenInvite::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Book swap', 'category' => 'other',
        ]);

        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->postJson("/api/v1/open-invites/{$invite->id}/interest")
            ->assertOk()->assertJsonPath('data.interested_count', 1);
        $this->deleteJson("/api/v1/open-invites/{$invite->id}/interest")
            ->assertOk()->assertJsonPath('data.interested_count', 0);
    }

    public function test_a_closed_invite_takes_no_more_interest(): void
    {
        $invite = OpenInvite::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Old idea', 'category' => 'other', 'status' => 'closed',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/open-invites/{$invite->id}/interest")->assertStatus(422);
    }

    public function test_only_the_author_can_convert_an_invite(): void
    {
        $invite = OpenInvite::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Trek', 'category' => 'outdoors',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/open-invites/{$invite->id}/convert-to-event", [
            'starts_at' => now()->addWeek()->toIso8601String(),
        ])->assertStatus(403);
    }

    public function test_stories_carry_tags_and_can_be_filtered_by_one(): void
    {
        $author = User::factory()->create();
        $this->actingAs($author, 'sanctum');

        $this->postJson('/api/v1/stories', [
            'title' => 'Kedarkantha in the snow',
            'body' => 'Four days, very cold.',
            'category' => 'travel',
            'tags' => ['Trekking', 'Photography'],
        ])->assertCreated()->assertJsonCount(2, 'data.tags');

        $this->postJson('/api/v1/stories', [
            'title' => 'Built a keyboard', 'body' => 'Soldering.', 'category' => 'making',
            'tags' => ['AI'],
        ])->assertCreated();

        $this->getJson('/api/v1/stories?tag=trekking')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Kedarkantha in the snow');
    }

    public function test_discover_ranks_stories_matching_your_interests_first(): void
    {
        $trekking = Tag::findOrCreateByName('Trekking', 'interest');
        $chess = Tag::findOrCreateByName('Chess', 'interest');

        $me = User::factory()->create();
        $me->tags()->attach($trekking->id, ['kind' => 'interest']);

        $author = User::factory()->create();

        // Created first, so recency alone would put it last.
        $matching = Story::create([
            'user_id' => $author->id, 'title' => 'A long trek', 'body' => 'x', 'category' => 'travel',
        ]);
        $matching->tags()->sync([$trekking->id]);

        $other = Story::create([
            'user_id' => $author->id, 'title' => 'A chess tournament', 'body' => 'x', 'category' => 'other',
        ]);
        $other->tags()->sync([$chess->id]);

        $this->actingAs($me, 'sanctum');
        $data = $this->getJson('/api/v1/stories/discover')->assertOk()->json('data');

        $this->assertSame($matching->id, $data[0]['id']);
        $this->assertCount(2, $data);
    }

    public function test_discover_excludes_your_own_stories(): void
    {
        $me = User::factory()->create();
        Story::create(['user_id' => $me->id, 'title' => 'Mine', 'body' => 'x', 'category' => 'other']);
        Story::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Theirs', 'body' => 'x', 'category' => 'other',
        ]);

        $this->actingAs($me, 'sanctum');
        $this->getJson('/api/v1/stories/discover')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Theirs');
    }
}
