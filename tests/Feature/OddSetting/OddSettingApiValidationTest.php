<?php

namespace Tests\Feature\OddSetting;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
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
                'currency' => 'USD',
                'user_type' => 'gold',
                'odd' => -1,
                'bet_amount' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bet_type', 'currency', 'user_type', 'odd', 'bet_amount'],
            ]);
    }

    public function test_update_validation_errors_return_422_envelope_with_errors(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $oddSetting = OddSetting::query()->create([
            'bet_type' => BetType::TWO_D,
            'currency' => Currency::MMK,
            'user_type' => OddSettingUserType::USER,
            'odd' => '80.00',
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
            'currency' => Currency::MMK,
            'user_type' => OddSettingUserType::USER,
            'odd' => '80.00',
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/odd-settings', [
                'bet_type' => BetType::TWO_D->value,
                'currency' => Currency::MMK->value,
                'user_type' => OddSettingUserType::USER->value,
                'odd' => '81.00',
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

    public function test_store_allows_same_bet_type_for_different_currency_or_user_type(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        OddSetting::query()->create([
            'bet_type' => BetType::TWO_D,
            'currency' => Currency::MMK,
            'user_type' => OddSettingUserType::USER,
            'odd' => '80.00',
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/odd-settings', [
                'bet_type' => BetType::TWO_D->value,
                'currency' => Currency::THB->value,
                'user_type' => OddSettingUserType::USER->value,
                'odd' => '80.00',
                'is_active' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('errors', null);
    }

    public function test_store_rejects_bet_amount_field_with_422_envelope(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/odd-settings', [
                'bet_type' => BetType::THREE_D->value,
                'currency' => Currency::THB->value,
                'user_type' => OddSettingUserType::VIP->value,
                'odd' => '10.00',
                'bet_amount' => 1000,
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bet_amount'],
            ]);
    }

    public function test_update_rejects_bet_amount_field_with_422_envelope(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $oddSetting = OddSetting::query()->create([
            'bet_type' => BetType::TWO_D,
            'currency' => Currency::MMK,
            'user_type' => OddSettingUserType::USER,
            'odd' => '80.00',
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/odd-settings/'.$oddSetting->id, [
                'bet_amount' => 1000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bet_amount'],
            ]);
    }

    public function test_store_requires_currency_and_user_type(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/odd-settings', [
                'bet_type' => BetType::THREE_D->value,
                'odd' => '10.00',
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['currency', 'user_type'],
            ]);
    }
}
