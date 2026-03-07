<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthFailureCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_registration_email_returns_422_with_email_error(): void
    {
        User::factory()->create([
            'email' => 'duplicate@example.com',
        ]);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Duplicate User',
            'email' => 'duplicate@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['email'],
            ]);
    }

    public function test_invalid_login_password_returns_401_with_credentials_error_envelope(): void
    {
        User::factory()->create([
            'email' => 'wrong-pass@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'wrong-pass@example.com',
            'password' => 'incorrect-password',
        ]);

        $response
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.credentials.0', 'The provided credentials are incorrect.')
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['credentials'],
            ]);
    }

    public function test_me_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_logout_without_token_returns_401(): void
    {
        $response = $this->postJson('/api/v1/logout');

        $response
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
