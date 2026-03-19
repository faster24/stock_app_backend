<?php

namespace Tests\Feature\OddSetting;

use App\Enums\BetType;
use App\Models\OddSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OddSettingApiValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_validation_errors_return_422_envelope_with_errors(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/odd-settings', [
                'bet_type' => 'INVALID',
                'odd' => -1,
                'bet_amount' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bet_type', 'odd', 'bet_amount'],
            ]);
    }

    public function test_update_validation_errors_return_422_envelope_with_errors(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $oddSetting = OddSetting::query()->create([
            'bet_type' => BetType::TWO_D,
            'odd' => '80.00',
            'bet_amount' => 1000,
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/odd-settings/'.$oddSetting->id, [
                'bet_type' => 'NOPE',
                'bet_amount' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bet_type', 'bet_amount'],
            ]);
    }

    public function test_store_rejects_duplicate_bet_type_with_422_envelope(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        OddSetting::query()->create([
            'bet_type' => BetType::TWO_D,
            'odd' => '80.00',
            'bet_amount' => 1000,
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/odd-settings', [
                'bet_type' => BetType::TWO_D->value,
                'odd' => '81.00',
                'bet_amount' => 2000,
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bet_type'],
            ]);
    }
}
