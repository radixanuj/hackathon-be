<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\RadixQuestion;
use App\Models\Tag;
use App\Models\TeachOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ask Radix and Teach Radix — Learn & Share, Phase 2. */
class AskAndTeachTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_question_can_be_answered_or_merely_volunteered_for(): void
    {
        $asker = User::factory()->create();
        $helper = User::factory()->create();

        $this->actingAs($asker, 'sanctum');
        $id = $this->postJson('/api/v1/questions', [
            'title' => 'Has anyone worked with BigQuery ML?',
            'body' => 'Deciding whether it is worth it.',
            'tags' => ['BigQuery', 'Data Modelling'],
        ])->assertCreated()->json('data.id');

        $this->actingAs($helper, 'sanctum');
        $this->postJson("/api/v1/questions/{$id}/answers", ['body' => 'We used it for churn.'])
            ->assertCreated();

        // Volunteering is the other half of the feature: "come talk to me".
        $this->postJson("/api/v1/questions/{$id}/volunteer", ['note' => 'Happy to call'])
            ->assertOk()
            ->assertJsonPath('data.volunteers_count', 1)
            ->assertJsonPath('data.answers_count', 1)
            ->assertJsonPath('data.i_volunteered', true);

        $this->deleteJson("/api/v1/questions/{$id}/volunteer")
            ->assertOk()->assertJsonPath('data.volunteers_count', 0);
    }

    public function test_you_cannot_volunteer_on_your_own_question(): void
    {
        $asker = User::factory()->create();
        $this->actingAs($asker, 'sanctum');

        $id = $this->postJson('/api/v1/questions', ['title' => 'Anything?'])->json('data.id');

        $this->postJson("/api/v1/questions/{$id}/volunteer")->assertStatus(422);
    }

    public function test_for_me_surfaces_questions_matching_your_expertise(): void
    {
        $bigQuery = Tag::findOrCreateByName('BigQuery', 'skill');
        $cooking = Tag::findOrCreateByName('Cooking', 'skill');

        $expert = User::factory()->create();
        $expert->tags()->attach($bigQuery->id, ['kind' => 'can_help_with']);

        $asker = User::factory()->create();

        $relevant = RadixQuestion::create(['user_id' => $asker->id, 'title' => 'BigQuery ML?']);
        $relevant->tags()->sync([$bigQuery->id]);

        $irrelevant = RadixQuestion::create(['user_id' => $asker->id, 'title' => 'Best knife?']);
        $irrelevant->tags()->sync([$cooking->id]);

        $this->actingAs($expert, 'sanctum');

        $this->getJson('/api/v1/questions?for_me=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $relevant->id)
            ->assertJsonPath('data.0.matches_my_profile', true);

        // Without the filter both are listed.
        $this->getJson('/api/v1/questions')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_for_me_never_returns_your_own_questions(): void
    {
        $tag = Tag::findOrCreateByName('BigQuery', 'skill');

        $user = User::factory()->create();
        $user->tags()->attach($tag->id, ['kind' => 'can_help_with']);

        $mine = RadixQuestion::create(['user_id' => $user->id, 'title' => 'Mine']);
        $mine->tags()->sync([$tag->id]);

        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/v1/questions?for_me=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_the_asker_can_accept_an_answer(): void
    {
        $asker = User::factory()->create();
        $helper = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $question = RadixQuestion::create(['user_id' => $asker->id, 'title' => 'How?']);
        $answer = $question->answers()->create(['user_id' => $helper->id, 'body' => 'Like this.']);

        // Not even an admin — accepting is the asker's own judgement.
        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/v1/question-answers/{$answer->id}/accept")->assertStatus(403);

        $this->actingAs($asker, 'sanctum');
        $this->postJson("/api/v1/question-answers/{$answer->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'answered')
            ->assertJsonPath('data.accepted_answer_id', $answer->id);
    }

    public function test_accepting_a_second_answer_unsets_the_first(): void
    {
        $asker = User::factory()->create();
        $question = RadixQuestion::create(['user_id' => $asker->id, 'title' => 'How?']);

        $first = $question->answers()->create(['user_id' => User::factory()->create()->id, 'body' => 'A']);
        $second = $question->answers()->create(['user_id' => User::factory()->create()->id, 'body' => 'B']);

        $this->actingAs($asker, 'sanctum');
        $this->postJson("/api/v1/question-answers/{$first->id}/accept")->assertOk();
        $this->postJson("/api/v1/question-answers/{$second->id}/accept")->assertOk();

        $this->assertFalse($first->fresh()->is_accepted);
        $this->assertTrue($second->fresh()->is_accepted);
        $this->assertSame($second->id, $question->fresh()->accepted_answer_id);
    }

    public function test_a_closed_question_takes_no_more_answers(): void
    {
        $question = RadixQuestion::create([
            'user_id' => User::factory()->create()->id, 'title' => 'Old', 'status' => 'closed',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/questions/{$question->id}/answers", ['body' => 'Late'])
            ->assertStatus(422);
    }

    public function test_a_teach_offer_gathers_interest_then_becomes_an_event(): void
    {
        $teacher = User::factory()->create();
        $this->actingAs($teacher, 'sanctum');

        $id = $this->postJson('/api/v1/teach-offers', [
            'title' => 'Figma for non-designers',
            'format' => 'workshop',
            'level' => 'beginner',
            'topic' => 'Figma',
            'min_interested' => 2,
            'duration_minutes' => 60,
        ])->assertCreated()->assertJsonPath('data.has_enough_interest', false)->json('data.id');

        $this->postJson("/api/v1/teach-offers/{$id}/interest")->assertStatus(422);

        $interested = User::factory()->count(2)->create();
        foreach ($interested as $user) {
            $this->actingAs($user, 'sanctum');
            $this->postJson("/api/v1/teach-offers/{$id}/interest")->assertOk();
        }

        $this->actingAs($teacher, 'sanctum');
        $this->getJson('/api/v1/teach-offers?ready=1')->assertOk()->assertJsonCount(1, 'data');

        $eventId = $this->postJson("/api/v1/teach-offers/{$id}/schedule", [
            'starts_at' => now()->addWeek()->toIso8601String(),
            'location' => 'Mumbai',
        ])->assertCreated()->json('data.id');

        // Everyone who was interested is carried over as going, plus the teacher.
        $event = Event::find($eventId);
        $this->assertSame(3, $event->going_count);
        $this->assertSame($teacher->id, $event->host_id);
        $this->assertSame(60, (int) $event->starts_at->diffInMinutes($event->ends_at));

        $this->assertSame('scheduled', TeachOffer::find($id)->status);
        $this->postJson("/api/v1/teach-offers/{$id}/schedule", [
            'starts_at' => now()->addWeeks(2)->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_only_the_owner_can_schedule_a_teach_offer(): void
    {
        $offer = TeachOffer::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Something', 'format' => 'session',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson("/api/v1/teach-offers/{$offer->id}/schedule", [
            'starts_at' => now()->addWeek()->toIso8601String(),
        ])->assertStatus(403);
    }
}
