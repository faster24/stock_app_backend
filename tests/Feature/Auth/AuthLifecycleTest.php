<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_success_returns_201_with_envelope_and_token(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('message', 'Registration successful.')
            ->assertJsonPath('data.user.email', 'jane@example.com')
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'message',
                'data' => ['user' => ['id', 'name', 'email'], 'token'],
                'errors',
            ]);

        $this->assertIsString($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_success_returns_200_with_envelope_and_token(): void
    {
        User::factory()->create([
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'john@example.com',
            'password' => 'password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.email', 'john@example.com')
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'message',
                'data' => ['user' => ['id', 'name', 'email'], 'token'],
                'errors',
            ]);

        $this->assertIsString($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_me_success_with_valid_token_returns_200_with_user_data(): void
    {
        $user = User::factory()->create([
            'email' => 'me@example.com',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Authenticated user profile.')
            ->assertJsonPath('data.user.email', 'me@example.com')
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'message',
                'data' => ['user' => ['id', 'name', 'email']],
                'errors',
            ]);
    }

    public function test_logout_success_revokes_current_token_and_denies_subsequent_me_access(): void
    {
        $user = User::factory()->create([
            'email' => 'logout@example.com',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $logoutResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/logout');

        $logoutResponse
            ->assertOk()
            ->assertJsonPath('message', 'Logout successful.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors',
            ]);

        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();

        $meResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me');

        $meResponse
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
