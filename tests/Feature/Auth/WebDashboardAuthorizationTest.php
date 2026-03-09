<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebDashboardAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_guest_request_is_blocked(): void
    {
        $response = $this->get('/dashboard');

        $response->assertForbidden();
    }

    public function test_dashboard_normal_authenticated_user_is_forbidden(): void
    {
        $user = User::factory()->normalUser()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertForbidden();
    }

    public function test_dashboard_admin_authenticated_user_is_allowed(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/dashboard');

        $response
            ->assertOk()
            ->assertSeeText('Admin dashboard.');
    }
}
