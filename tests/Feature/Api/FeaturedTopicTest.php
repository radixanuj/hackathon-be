<?php

namespace Tests\Feature\Api;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeaturedTopicTest extends TestCase
{
    use RefreshDatabase;

    public function test_featured_topics_come_back_in_their_curated_order(): void
    {
        // Deliberately ranked against usage: the curated order has to win over
        // whatever happens to be the most-listed skill.
        Tag::findOrCreateByName('Negotiation', 'skill')->update(['featured_rank' => 2, 'usage_count' => 90]);
        Tag::findOrCreateByName('Python', 'skill')->update(['featured_rank' => 1, 'usage_count' => 3]);
        Tag::findOrCreateByName('Kubernetes', 'skill')->update(['usage_count' => 99]);

        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->getJson('/api/v1/tags?type=skill&featured=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Python')
            ->assertJsonPath('data.0.is_featured', true)
            ->assertJsonPath('data.1.name', 'Negotiation');

        // Unfiltered, the most-used one still leads and nothing is hidden.
        $this->getJson('/api/v1/tags?type=skill')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Kubernetes');
    }

    public function test_a_topic_can_be_looked_up_by_slug(): void
    {
        Tag::findOrCreateByName('Prompt Engineering', 'skill');
        Tag::findOrCreateByName('Vibe coding', 'skill');

        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->getJson('/api/v1/tags?slug=vibe-coding')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Vibe coding');
    }
}
