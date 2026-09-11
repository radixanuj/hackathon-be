<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Notification;
use App\Models\Story;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Notifications — cross-cutting. */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_acting_on_someone_elses_thing_notifies_only_them(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $bystander = User::factory()->create();

        $this->actingAs($host, 'sanctum');
        $event = $this->postJson('/api/v1/events', [
            'title' => 'Sunday run', 'category' => 'sports', 'starts_at' => now()->addWeek()->toIso8601String(),
        ])->json('data.id');

        $this->actingAs($guest, 'sanctum');
        $this->postJson("/api/v1/events/{$event}/rsvp", ['status' => 'going'])->assertOk();

        $this->assertSame(1, Notification::where('user_id', $host->id)->count());
        $this->assertSame(0, Notification::where('user_id', $guest->id)->count());
        $this->assertSame(0, Notification::where('user_id', $bystander->id)->count());

        $notification = Notification::where('user_id', $host->id)->first();
        $this->assertSame('event.rsvp', $notification->type);
        $this->assertSame($guest->id, $notification->actor_id);
        $this->assertSame('do_together', $notification->category);
    }

    public function test_your_own_actions_never_notify_you(): void
    {
        $author = User::factory()->create();
        $story = Story::create([
            'user_id' => $author->id, 'title' => 'A long walk', 'body' => 'Details.', 'category' => 'travel',
        ]);

        $this->actingAs($author, 'sanctum');
        $this->postJson("/api/v1/stories/{$story->id}/react", ['reaction' => 'clap'])->assertOk();

        $this->assertSame(0, Notification::count());
    }

    public function test_a_repeated_reaction_does_not_notify_twice(): void
    {
        $author = User::factory()->create();
        $reader = User::factory()->create();
        $story = Story::create([
            'user_id' => $author->id, 'title' => 'A long walk', 'body' => 'Details.', 'category' => 'travel',
        ]);

        $this->actingAs($reader, 'sanctum');
        $this->postJson("/api/v1/stories/{$story->id}/react", ['reaction' => 'clap'])->assertOk();
        $this->postJson("/api/v1/stories/{$story->id}/react", ['reaction' => 'heart'])->assertOk();

        $this->assertSame(1, Notification::where('user_id', $author->id)->count());
    }

    public function test_asking_a_question_does_not_notify_people_who_merely_match_the_tag(): void
    {
        $bigQuery = Tag::findOrCreateByName('BigQuery', 'skill');

        $asker = User::factory()->create();
        $expert = User::factory()->create();
        $expert->tags()->attach($bigQuery->id, ['kind' => 'can_help_with']);

        $this->actingAs($asker, 'sanctum');
        $this->postJson('/api/v1/questions', [
            'title' => 'How do I partition this?', 'tags' => ['BigQuery'],
        ])->assertCreated();

        // Matching people find it through ?for_me=1 and the dashboard. Pushing it
        // into their inbox would be noise they never asked for.
        $this->assertSame(0, Notification::count());
    }

    public function test_creating_an_event_in_a_group_does_not_notify_the_members(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $this->actingAs($owner, 'sanctum');
        $created = $this->postJson('/api/v1/groups', ['name' => 'Runners'])->json('data');
        [$group, $slug] = [$created['id'], $created['slug']];

        $this->actingAs($member, 'sanctum');
        $this->postJson("/api/v1/groups/{$slug}/join")->assertOk();

        // Joining tells the owner — that is their group.
        $this->assertSame(1, Notification::where('user_id', $owner->id)->count());

        $this->actingAs($owner, 'sanctum');
        $this->postJson('/api/v1/events', [
            'title' => 'Group run', 'category' => 'sports',
            'starts_at' => now()->addWeek()->toIso8601String(), 'interest_group_id' => $group,
        ])->assertCreated();

        // A new event belongs in the events list, not in every member's inbox.
        $this->assertSame(0, Notification::where('user_id', $member->id)->count());
    }

    public function test_attendees_are_told_when_an_event_they_joined_is_cancelled(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();

        $this->actingAs($host, 'sanctum');
        $event = $this->postJson('/api/v1/events', [
            'title' => 'Sunday run', 'category' => 'sports', 'starts_at' => now()->addWeek()->toIso8601String(),
        ])->json('data.id');

        $this->actingAs($guest, 'sanctum');
        $this->postJson("/api/v1/events/{$event}/rsvp", ['status' => 'going'])->assertOk();

        $this->actingAs($host, 'sanctum');
        $this->patchJson("/api/v1/events/{$event}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(1, Notification::where('user_id', $guest->id)->where('type', 'event.cancelled')->count());
    }

    public function test_the_index_returns_only_your_own_notifications(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $this->make($mine, 3);
        $this->make($theirs, 5);

        $this->actingAs($mine, 'sanctum');
        $response = $this->getJson('/api/v1/notifications')->assertOk();

        $this->assertCount(3, $response->json('data'));
        $this->assertSame(3, $response->json('summary.unread'));
    }

    public function test_you_cannot_touch_someone_elses_notification(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $hers = $this->make($theirs, 1)->first();

        $this->actingAs($mine, 'sanctum');
        $this->patchJson("/api/v1/notifications/{$hers->id}/read")->assertForbidden();
        $this->postJson("/api/v1/notifications/{$hers->id}/archive")->assertForbidden();

        $this->assertNull($hers->fresh()->read_at);
        $this->assertNull($hers->fresh()->archived_at);
    }

    public function test_archiving_keeps_the_row_and_moves_it_between_the_two_scopes(): void
    {
        $user = User::factory()->create();
        $notification = $this->make($user, 1)->first();

        $this->actingAs($user, 'sanctum');
        $this->postJson("/api/v1/notifications/{$notification->id}/archive")->assertOk();

        // Archived, not deleted — and filing it away counts as having seen it.
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
        $this->assertNotNull($notification->fresh()->archived_at);
        $this->assertNotNull($notification->fresh()->read_at);

        $this->assertCount(0, $this->getJson('/api/v1/notifications?scope=inbox')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/notifications?scope=archived')->json('data'));

        $this->deleteJson("/api/v1/notifications/{$notification->id}/archive")->assertOk();
        $this->assertCount(1, $this->getJson('/api/v1/notifications?scope=inbox')->json('data'));
    }

    public function test_there_is_no_way_to_delete_one(): void
    {
        $user = User::factory()->create();
        $notification = $this->make($user, 1)->first();

        $this->actingAs($user, 'sanctum');
        // The two DELETE routes undo a state change (unread, unarchive); there is
        // no route that removes the row, so this resolves to nothing at all.
        $this->deleteJson("/api/v1/notifications/{$notification->id}")->assertNotFound();
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    public function test_read_all_and_archive_all_cover_the_inbox(): void
    {
        $user = User::factory()->create();
        $this->make($user, 4);

        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->assertSame(0, Notification::where('user_id', $user->id)->unread()->count());

        $this->postJson('/api/v1/notifications/archive-all')->assertOk();
        $this->assertSame(0, Notification::where('user_id', $user->id)->inbox()->count());
        $this->assertSame(4, Notification::where('user_id', $user->id)->count());
    }

    public function test_archive_all_can_spare_anything_still_unread(): void
    {
        $user = User::factory()->create();
        $rows = $this->make($user, 3);
        $rows->first()->markRead();

        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/v1/notifications/archive-all?only_read=1')->assertOk();

        $this->assertSame(2, Notification::where('user_id', $user->id)->inbox()->count());
        $this->assertSame(1, Notification::where('user_id', $user->id)->archived()->count());
    }

    public function test_the_summary_counts_the_inbox_by_category(): void
    {
        $user = User::factory()->create();
        $this->make($user, 2, 'event.rsvp');
        $this->make($user, 1, 'story.reaction')->first()->archive();

        $this->actingAs($user, 'sanctum');
        $summary = $this->getJson('/api/v1/notifications/summary')->assertOk()->json('data');

        $this->assertSame(2, $summary['unread']);
        $this->assertSame(2, $summary['inbox']);
        $this->assertSame(1, $summary['archived']);
        $this->assertSame(2, $summary['by_category']['do_together']['unread']);
        $this->assertSame(0, $summary['by_category']['celebrate']['inbox']);
    }

    public function test_filters_narrow_the_list(): void
    {
        $user = User::factory()->create();
        $this->make($user, 2, 'event.rsvp');
        $this->make($user, 1, 'story.reaction')->first()->markRead();

        $this->actingAs($user, 'sanctum');

        $this->assertCount(2, $this->getJson('/api/v1/notifications?status=unread')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/notifications?status=read')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/notifications?category=celebrate')->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/notifications?type=event.rsvp')->json('data'));
    }

    public function test_every_type_in_the_catalogue_maps_to_a_real_category(): void
    {
        foreach (Notification::TYPES as $type => [$category, $icon]) {
            $this->assertContains($category, Notification::CATEGORIES, "{$type} has an unknown category");
            $this->assertNotSame('', trim($icon), "{$type} has no icon");
        }
    }

    /** @return \Illuminate\Support\Collection<int, Notification> */
    protected function make(User $user, int $count, string $type = 'event.rsvp')
    {
        $event = Event::create([
            'host_id' => $user->id, 'title' => 'Something', 'category' => 'other',
            'starts_at' => now()->addWeek(),
        ]);

        return collect(range(1, $count))->map(fn (int $i) => Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'category' => Notification::categoryFor($type),
            'title' => "Notification {$i}",
            'subject_type' => $event->getMorphClass(),
            'subject_id' => $event->getKey(),
        ]));
    }
}
