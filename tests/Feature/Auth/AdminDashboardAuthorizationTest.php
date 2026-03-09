<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_guest_request_returns_401_envelope(): void
    {
        $response = $this->getJson('/api/v1/admin/dashboard');

        $response
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.')
            ->assertJsonMissingPath('exception');
    }

    public function test_admin_dashboard_normal_user_returns_403_envelope_without_stack_trace(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/dashboard');

        $response
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    }

    public function test_admin_dashboard_admin_user_returns_200_envelope(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Admin dashboard data retrieved successfully.')
            ->assertJsonPath('data.dashboard.scope', 'admin')
            ->assertJsonPath('errors', null)
            ->assertJsonMissingPath('exception');
    }
}
