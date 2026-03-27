<?php

namespace Tests\Feature\AppSetting;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppMaintenanceSettingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_read_default_maintenance_setting_without_authentication(): void
    {
        $this->getJson('/api/v1/app-settings/maintenance')
            ->assertOk()
            ->assertJsonPath('message', 'Maintenance setting retrieved successfully.')
            ->assertJsonPath('data.maintenance.is_enabled', false)
            ->assertJsonPath('data.maintenance.message', null)
            ->assertJsonPath('errors', null);
    }

    public function test_guest_cannot_update_maintenance_setting(): void
    {
        $this->putJson('/api/v1/admin/app-settings/maintenance', [
            'is_enabled' => true,
            'message' => 'Scheduled maintenance.',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');
    }

    public function test_non_admin_cannot_update_maintenance_setting(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/app-settings/maintenance', [
                'is_enabled' => true,
                'message' => 'Scheduled maintenance.',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');
    }

    public function test_admin_can_upsert_and_read_maintenance_setting(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/app-settings/maintenance', [
                'is_enabled' => true,
                'message' => 'We are doing system maintenance.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Maintenance setting updated successfully.')
            ->assertJsonPath('data.maintenance.is_enabled', true)
            ->assertJsonPath('data.maintenance.message', 'We are doing system maintenance.')
            ->assertJsonPath('errors', null);

        $this->assertDatabaseHas('app_maintenance_settings', [
            'is_enabled' => 1,
            'message' => 'We are doing system maintenance.',
        ]);

        $this->getJson('/api/v1/app-settings/maintenance')
            ->assertOk()
            ->assertJsonPath('data.maintenance.is_enabled', true)
            ->assertJsonPath('data.maintenance.message', 'We are doing system maintenance.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/app-settings/maintenance', [
                'is_enabled' => false,
                'message' => 'This message should be cleared.',
            ])
            ->assertOk()
            ->assertJsonPath('data.maintenance.is_enabled', false)
            ->assertJsonPath('data.maintenance.message', null);

        $this->assertDatabaseHas('app_maintenance_settings', [
            'is_enabled' => 0,
            'message' => null,
        ]);

        $this->assertSame(1, (int) DB::table('app_maintenance_settings')->count());
    }

    public function test_admin_update_requires_valid_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/app-settings/maintenance', [
                'message' => str_repeat('a', 256),
                'unexpected_field' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('errors.is_enabled.0', 'The is enabled field is required.')
            ->assertJsonPath('errors.message.0', 'The message field must not be greater than 255 characters.')
            ->assertJsonPath('errors.unexpected_field.0', 'The unexpected_field field is not allowed.');
    }
}
