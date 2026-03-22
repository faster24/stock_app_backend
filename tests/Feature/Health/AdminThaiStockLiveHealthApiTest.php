<?php

namespace Tests\Feature\Health;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminThaiStockLiveHealthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_request_returns_401_envelope(): void
    {
        $this->getJson('/api/v1/admin/health/thaistock2d-live')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');
    }

    public function test_non_admin_request_returns_403_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/health/thaistock2d-live')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');
    }

    public function test_admin_gets_200_when_upstream_is_healthy(): void
    {
        Http::fake([
            'https://api.thaistock2d.com/live' => Http::response([
                'result' => [],
            ], 200),
        ]);

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/health/thaistock2d-live')
            ->assertOk()
            ->assertJsonPath('message', 'ThaiStock2D live health check passed.')
            ->assertJsonPath('data.health.service', 'thaistock2d_live')
            ->assertJsonPath('data.health.url', 'https://api.thaistock2d.com/live')
            ->assertJsonPath('data.health.healthy', true)
            ->assertJsonPath('data.health.upstream_status', 200)
            ->assertJsonPath('errors', null);
    }

    public function test_admin_gets_503_when_upstream_is_non_2xx(): void
    {
        Http::fake([
            'https://api.thaistock2d.com/live' => Http::response([
                'message' => 'upstream error',
            ], 500),
        ]);

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/health/thaistock2d-live')
            ->assertStatus(503)
            ->assertJsonPath('message', 'ThaiStock2D live health check failed.')
            ->assertJsonPath('data.health.healthy', false)
            ->assertJsonPath('data.health.upstream_status', 500)
            ->assertJsonPath('errors', null);
    }

    public function test_admin_gets_503_when_upstream_payload_is_invalid(): void
    {
        Http::fake([
            'https://api.thaistock2d.com/live' => Http::response([
                'foo' => 'bar',
            ], 200),
        ]);

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/health/thaistock2d-live')
            ->assertStatus(503)
            ->assertJsonPath('message', 'ThaiStock2D live health check failed.')
            ->assertJsonPath('data.health.healthy', false)
            ->assertJsonPath('data.health.upstream_status', 200)
            ->assertJsonPath('errors', null);
    }

    public function test_admin_gets_503_when_request_throws_exception(): void
    {
        Http::fake(function (): never {
            throw new \RuntimeException('connection refused');
        });

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/health/thaistock2d-live')
            ->assertStatus(503)
            ->assertJsonPath('message', 'ThaiStock2D live health check failed.')
            ->assertJsonPath('data.health.healthy', false)
            ->assertJsonPath('data.health.upstream_status', null)
            ->assertJsonPath('errors', null);
    }
}
