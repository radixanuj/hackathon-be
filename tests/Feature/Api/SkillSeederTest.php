<?php

namespace Tests\Feature\Api;

use App\Models\Tag;
use App\Models\User;
use Database\Seeders\SkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The skills export and the org chart disagree about names in small ways, so the
 * seeder matches on a ladder. These cases are the real disagreements in
 * database/data/skills.json — if that file is replaced, expect to revisit them.
 */
class SkillSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_matches_names_the_two_exports_spell_differently(): void
    {
        $cases = [
            // Roster name              => how the skills export writes it
            'Jeet Dholakia' => 'exactly the same but for a middle name',
            'Avaneesh Sharma' => 'Avaneesh Veereshwar Sharma',
            'Clifford DeSouza' => 'Clifford De Souza',
            'Aqueeb Mohammad' => 'Mohammad Aqueeb, the other way round',
        ];

        foreach (array_keys($cases) as $name) {
            User::factory()->create(['name' => $name]);
        }

        $this->seed(SkillSeeder::class);

        foreach (array_keys($cases) as $name) {
            $user = User::where('name', $name)->first();

            $this->assertGreaterThan(
                0,
                $user->tagsOfKind('can_help_with')->count(),
                "{$name} ({$cases[$name]}) came away with no skills",
            );
        }
    }

    public function test_a_name_on_nobody_is_skipped_rather_than_invented(): void
    {
        User::factory()->create(['name' => 'Jeet Dholakia']);

        $this->seed(SkillSeeder::class);

        // The export lists far more people than this roster has.
        $this->assertSame(1, User::count());
    }

    public function test_it_replaces_the_seeded_expertise_but_leaves_the_rest_alone(): void
    {
        $user = User::factory()->create(['name' => 'Jeet Dholakia']);

        $madeUp = Tag::findOrCreateByName('Underwater Basket Weaving', 'skill');
        $wants = Tag::findOrCreateByName('Kubernetes', 'skill');
        $likes = Tag::findOrCreateByName('F1', 'interest');

        $user->tags()->attach($madeUp->id, ['kind' => 'can_talk_about']);
        $user->tags()->attach($madeUp->id, ['kind' => 'can_help_with']);
        $user->tags()->attach($wants->id, ['kind' => 'want_to_learn']);
        $user->tags()->attach($likes->id, ['kind' => 'interest']);

        $this->seed(SkillSeeder::class);

        $names = fn (string $kind) => $user->tagsOfKind($kind)->pluck('name')->all();

        $this->assertNotContains('Underwater Basket Weaving', $names('can_talk_about'));
        $this->assertNotContains('Underwater Basket Weaving', $names('can_help_with'));

        // Nothing in the export speaks to these two, so they are left as they were.
        $this->assertSame(['Kubernetes'], $names('want_to_learn'));
        $this->assertSame(['F1'], $names('interest'));
    }

    public function test_the_headline_skills_are_a_subset_of_what_they_can_help_with(): void
    {
        $user = User::factory()->create(['name' => 'Jeet Dholakia']);

        $this->seed(SkillSeeder::class);

        $talk = $user->tagsOfKind('can_talk_about')->pluck('tags.id')->all();
        $help = $user->tagsOfKind('can_help_with')->pluck('tags.id')->all();

        $this->assertNotEmpty($talk);
        $this->assertLessThanOrEqual(count($help), count($talk));
        $this->assertEmpty(array_diff($talk, $help));
    }

    public function test_overlong_skills_are_trimmed_to_what_the_api_accepts(): void
    {
        User::factory()->create(['name' => 'Jeet Dholakia']);
        User::factory()->create(['name' => 'Harsha Balwani']);
        User::factory()->create(['name' => 'Marina Manuel']);

        $this->seed(SkillSeeder::class);

        // The tag endpoints validate names at 60 characters; a skill longer than
        // that would store fine and then be uneditable through the API.
        $this->assertSame(0, Tag::whereRaw('length(name) > 60')->count());
    }
}
