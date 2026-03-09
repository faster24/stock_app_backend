<?php

namespace Tests\Feature\OddSetting;

use App\Enums\BetType;
use App\Models\OddSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OddSettingApiWriteAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_write_odd_settings(): void
    {
        $oddSetting = OddSetting::query()->create([
            'bet_type' => BetType::STRAIGHT,
            'odd' => '80.00',
            'bet_amount' => 1000,
            'is_active' => true,
        ]);

        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $payload = [
            'bet_type' => BetType::PERMUTATION->value,
            'odd' => '10.00',
            'bet_amount' => 2000,
            'is_active' => true,
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/odd-settings', $payload)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/odd-settings/'.$oddSetting->id, $payload)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/admin/odd-settings/'.$oddSetting->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');
    }

    public function test_admin_can_create_update_and_delete_odd_settings(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/odd-settings', [
                'bet_type' => BetType::PERMUTATION->value,
                'odd' => '10.00',
                'bet_amount' => 2000,
                'is_active' => true,
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('message', 'Odd setting created successfully.')
            ->assertJsonPath('data.odd_setting.bet_type', BetType::PERMUTATION->value)
            ->assertJsonPath('errors', null);

        $oddSettingId = $createResponse->json('data.odd_setting.id');

        $this->assertDatabaseHas('odd_settings', [
            'id' => $oddSettingId,
            'bet_type' => BetType::PERMUTATION->value,
            'bet_amount' => 2000,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/odd-settings/'.$oddSettingId, [
                'odd' => '11.50',
                'bet_amount' => 2500,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Odd setting updated successfully.')
            ->assertJsonPath('data.odd_setting.bet_amount', 2500)
            ->assertJsonPath('errors', null);

        $this->assertDatabaseHas('odd_settings', [
            'id' => $oddSettingId,
            'bet_amount' => 2500,
            'is_active' => 0,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/admin/odd-settings/'.$oddSettingId)
            ->assertOk()
            ->assertJsonPath('message', 'Odd setting deleted successfully.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors', null);

        $this->assertDatabaseMissing('odd_settings', [
            'id' => $oddSettingId,
        ]);
    }

    public function test_admin_gets_not_found_for_missing_odd_setting_on_update_and_delete(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/odd-settings/999999', [
                'odd' => '11.00',
            ])
            ->assertStatus(404)
            ->assertJsonStructure([
                'message',
            ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/admin/odd-settings/999999')
            ->assertStatus(404)
            ->assertJsonStructure([
                'message',
            ]);
    }
}
