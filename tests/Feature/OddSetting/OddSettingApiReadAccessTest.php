<?php

namespace Tests\Feature\OddSetting;

use App\Enums\BetType;
use App\Models\OddSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OddSettingApiReadAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_list_and_show_odd_settings(): void
    {
        $oddSetting = OddSetting::query()->create([
            'bet_type' => BetType::STRAIGHT,
            'odd' => '80.00',
            'bet_amount' => 1000,
            'is_active' => true,
        ]);

        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/odd-settings')
            ->assertOk()
            ->assertJsonPath('message', 'Odd settings retrieved successfully.')
            ->assertJsonPath('data.odd_settings.0.id', $oddSetting->id)
            ->assertJsonPath('errors', null);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/odd-settings/'.$oddSetting->id)
            ->assertOk()
            ->assertJsonPath('message', 'Odd setting retrieved successfully.')
            ->assertJsonPath('data.odd_setting.id', $oddSetting->id)
            ->assertJsonPath('errors', null);
    }

    public function test_guests_cannot_read_odd_settings(): void
    {
        $oddSetting = OddSetting::query()->create([
            'bet_type' => BetType::STRAIGHT,
            'odd' => '80.00',
            'bet_amount' => 1000,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/odd-settings')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');

        $this->getJson('/api/v1/odd-settings/'.$oddSetting->id)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');
    }

    public function test_authenticated_user_gets_not_found_for_missing_odd_setting(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/odd-settings/999999')
            ->assertStatus(404)
            ->assertJsonStructure([
                'message',
            ]);
    }
}
