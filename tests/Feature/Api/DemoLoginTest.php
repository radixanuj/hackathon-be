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

    public function test_real_email_password_login_still_works(): void
    {
        $user = User::factory()->create(['email' => 'real@radix.email']);

        $this->postJson('/api/v1/auth/login', ['email' => 'real@radix.email', 'password' => 'Radix123'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id);
    }
}
