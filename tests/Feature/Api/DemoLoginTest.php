<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_known_name_and_the_shared_password_signs_you_in(): void
    {
        $user = User::factory()->create(['name' => 'Anuj Maurya']);

        $this->postJson('/api/v1/auth/demo-login', ['name' => 'anuj maurya', 'password' => 'Radix123'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name']]]);
    }

    public function test_the_token_works_on_a_protected_route(): void
    {
        User::factory()->create(['name' => 'Anuj Maurya']);

        $token = $this->postJson('/api/v1/auth/demo-login', [
            'name' => 'Anuj Maurya', 'password' => 'Radix123',
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.name', 'Anuj Maurya');
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        User::factory()->create(['name' => 'Anuj Maurya']);

        $this->postJson('/api/v1/auth/demo-login', ['name' => 'Anuj Maurya', 'password' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_an_unknown_name_creates_a_profile_with_a_quest(): void
    {
        User::factory()->count(8)->create();

        $this->postJson('/api/v1/auth/demo-login', ['name' => 'Brand New', 'password' => 'Radix123'])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Brand New')
            ->assertJsonPath('data.user.is_new_joiner', true);

        $created = User::where('name', 'Brand New')->first();
        $this->assertSame('brand-new@radix.email', $created->email);
        $this->assertCount(5, $created->quest->targets);
    }

    public function test_auto_create_can_be_turned_off(): void
    {
        config()->set('radix.demo_login.auto_create', false);

        $this->postJson('/api/v1/auth/demo-login', ['name' => 'Nobody Here', 'password' => 'Radix123'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_the_whole_endpoint_can_be_disabled(): void
    {
        config()->set('radix.demo_login.enabled', false);

        $this->postJson('/api/v1/auth/demo-login', ['name' => 'Anyone', 'password' => 'Radix123'])
            ->assertStatus(404);
    }

    public function test_signing_in_by_id_picks_the_right_person(): void
    {
        // Two people one keystroke apart — the reason the screen sends an id.
        $sahar = User::factory()->create(['name' => 'Sahar Khan']);
        $saif = User::factory()->create(['name' => 'Saif Khan']);

        $this->postJson('/api/v1/auth/demo-login', ['user_id' => $saif->id, 'password' => 'Radix123'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $saif->id)
            ->assertJsonPath('data.user.name', 'Saif Khan');

        $this->assertNotSame($sahar->id, $saif->id);
    }

    public function test_signing_in_by_id_still_needs_the_shared_password(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/demo-login', ['user_id' => $user->id, 'password' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_an_id_that_is_nobody_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/demo-login', ['user_id' => 9999, 'password' => 'Radix123'])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
    }

    public function test_it_needs_either_an_id_or_a_name(): void
    {
        $this->postJson('/api/v1/auth/demo-login', ['password' => 'Radix123'])
            ->assertStatus(422)->assertJsonValidationErrors(['user_id', 'name']);
    }

    public function test_a_deactivated_person_cannot_sign_in_by_id(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->postJson('/api/v1/auth/demo-login', ['user_id' => $user->id, 'password' => 'Radix123'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_the_directory_searches_names_without_a_token(): void
    {
        User::factory()->create(['name' => 'Sahar Khan', 'job_title' => 'Associate Director']);
        User::factory()->create(['name' => 'Saif Khan']);
        User::factory()->create(['name' => 'Someone Else']);

        $response = $this->getJson('/api/v1/auth/directory?q=kh')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertEqualsCanonicalizing(
            ['Sahar Khan', 'Saif Khan'],
            array_column($response->json('data'), 'name'),
        );
    }

    public function test_the_directory_hands_out_no_contact_details(): void
    {
        User::factory()->create(['name' => 'Sahar Khan', 'email' => 'sahar@radix.email']);

        $person = $this->getJson('/api/v1/auth/directory?q=Sahar')->assertOk()->json('data.0');

        // It is readable by anyone who can load the sign-in page, so it carries
        // only what is needed to tell two colleagues apart.
        $this->assertSame(['id', 'name', 'job_title', 'team', 'location'], array_keys($person));
    }

    public function test_the_directory_leaves_out_deactivated_people(): void
    {
        User::factory()->create(['name' => 'Gone Away', 'is_active' => false]);

        $this->getJson('/api/v1/auth/directory?q=Gone')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_directory_is_off_when_demo_sign_in_is(): void
    {
        config()->set('radix.demo_login.enabled', false);

        $this->getJson('/api/v1/auth/directory?q=a')->assertStatus(404);
    }

    public function test_real_email_password_login_still_works(): void
    {
        $user = User::factory()->create(['email' => 'real@radix.email']);

        $this->postJson('/api/v1/auth/login', ['email' => 'real@radix.email', 'password' => 'Radix123'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id);
    }
}
