<?php

namespace Tests\Feature\Wallet;

use App\Enums\BankName;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletBankInfoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_bank_info_endpoint(): void
    {
        $this->getJson('/api/v1/me/bank-info')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');
    }

    public function test_authenticated_user_can_create_show_update_and_clear_own_bank_info_without_affecting_other_user(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $otherUser = User::factory()->normalUser()->create();
        Wallet::query()->create([
            'user_id' => $otherUser->id,
            'bank_name' => BankName::AYA->value,
            'account_name' => 'Other User',
            'account_number' => '555001',
        ]);

        $createPayload = [
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'Main User',
            'account_number' => '111222333',
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/me/bank-info', $createPayload)
            ->assertStatus(201)
            ->assertJsonPath('message', 'Bank info created successfully.')
            ->assertJsonPath('data.bank_info.bank_name', BankName::KBZ->value)
            ->assertJsonPath('errors', null);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'Main User',
            'account_number' => '111222333',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/bank-info')
            ->assertOk()
            ->assertJsonPath('message', 'Bank info retrieved successfully.')
            ->assertJsonPath('data.bank_info.account_name', 'Main User')
            ->assertJsonPath('errors', null);

        $this->travel(31)->days();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/me/bank-info', [
                'bank_name' => BankName::CB->value,
                'account_name' => 'Main User Updated',
                'account_number' => '999000111',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Bank info updated successfully.')
            ->assertJsonPath('data.bank_info.bank_name', BankName::CB->value)
            ->assertJsonPath('errors', null);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $otherUser->id,
            'bank_name' => BankName::AYA->value,
            'account_name' => 'Other User',
            'account_number' => '555001',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/me/bank-info')
            ->assertOk()
            ->assertJsonPath('message', 'Bank info cleared successfully.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors', null);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'bank_name' => null,
            'account_name' => null,
            'account_number' => null,
        ]);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $otherUser->id,
            'bank_name' => BankName::AYA->value,
            'account_name' => 'Other User',
            'account_number' => '555001',
        ]);
    }

    public function test_invalid_payload_returns_422_envelope_with_field_errors(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/me/bank-info', [
                'bank_name' => 'INVALID',
                'account_name' => '',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bank_name', 'account_name', 'account_number'],
            ]);

        Wallet::query()->create([
            'user_id' => $user->id,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'Main User',
            'account_number' => '111222333',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/me/bank-info', [
                'bank_name' => 'INVALID',
                'account_number' => '',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bank_name', 'account_number'],
            ]);
    }

    public function test_partial_update_preserves_unspecified_bank_info_fields(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        Wallet::query()->create([
            'user_id' => $user->id,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'Initial Name',
            'account_number' => '123456789',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/me/bank-info', [
                'account_name' => 'Updated Name Only',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Bank info updated successfully.')
            ->assertJsonPath('data.bank_info.bank_name', BankName::KBZ->value)
            ->assertJsonPath('data.bank_info.account_name', 'Updated Name Only')
            ->assertJsonPath('data.bank_info.account_number', '123456789')
            ->assertJsonPath('errors', null);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'Updated Name Only',
            'account_number' => '123456789',
        ]);
    }

    public function test_repeated_create_and_update_are_idempotent_and_keep_single_wallet_row(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/me/bank-info', [
                'bank_name' => BankName::KBZ->value,
                'account_name' => 'Main User',
                'account_number' => '111222333',
            ])
            ->assertStatus(201)
            ->assertJsonPath('errors', null);

        $this->travel(31)->days();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/me/bank-info', [
                'bank_name' => BankName::YOMA->value,
                'account_name' => 'Main User Updated',
                'account_number' => '777888999',
            ])
            ->assertOk()
            ->assertJsonPath('errors', null);

        $this->assertSame(1, Wallet::query()->where('user_id', $user->id)->count());

        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'bank_name' => BankName::YOMA->value,
            'account_name' => 'Main User Updated',
            'account_number' => '777888999',
        ]);
    }

    public function test_first_time_bank_info_creation_is_not_rate_limited(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/me/bank-info', [
                'bank_name' => BankName::KBZ->value,
                'account_name' => 'New User',
                'account_number' => '100200300',
            ])
            ->assertStatus(201)
            ->assertJsonPath('errors', null);
    }

    public function test_update_within_30_days_is_rejected_with_422(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        Wallet::query()->create([
            'user_id' => $user->id,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'User',
            'account_number' => '111000111',
            'bank_info_updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/me/bank-info', [
                'bank_name' => BankName::AYA->value,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Bank info can only be updated once every 30 days.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.bank_info.0', 'Bank info can only be updated once every 30 days.')
            ->assertJsonStructure(['errors' => ['next_allowed_at']]);
    }

    public function test_post_within_30_days_is_rejected_with_422(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        Wallet::query()->create([
            'user_id' => $user->id,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'User',
            'account_number' => '111000111',
            'bank_info_updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/me/bank-info', [
                'bank_name' => BankName::AYA->value,
                'account_name' => 'User Updated',
                'account_number' => '222000222',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Bank info can only be updated once every 30 days.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.bank_info.0', 'Bank info can only be updated once every 30 days.')
            ->assertJsonStructure(['errors' => ['next_allowed_at']]);
    }

    public function test_update_is_allowed_after_30_day_cooldown(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        Wallet::query()->create([
            'user_id' => $user->id,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'User',
            'account_number' => '111000111',
            'bank_info_updated_at' => now()->subDays(30)->subSecond(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/me/bank-info', [
                'bank_name' => BankName::AYA->value,
            ])
            ->assertOk()
            ->assertJsonPath('errors', null);
    }

    public function test_update_at_exactly_30_days_is_allowed(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        Wallet::query()->create([
            'user_id' => $user->id,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'User',
            'account_number' => '111000111',
            'bank_info_updated_at' => now()->subDays(30),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/me/bank-info', [
                'bank_name' => BankName::AYA->value,
            ])
            ->assertOk()
            ->assertJsonPath('errors', null);
    }

    public function test_update_1_second_before_30_days_is_blocked(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        Wallet::query()->create([
            'user_id' => $user->id,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'User',
            'account_number' => '111000111',
            'bank_info_updated_at' => now()->subDays(30)->addSecond(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/me/bank-info', [
                'bank_name' => BankName::AYA->value,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.bank_info.0', 'Bank info can only be updated once every 30 days.');
    }

    public function test_create_after_clear_is_not_rate_limited(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        Wallet::query()->create([
            'user_id' => $user->id,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'User',
            'account_number' => '111000111',
            'bank_info_updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/me/bank-info')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/me/bank-info', [
                'bank_name' => BankName::AYA->value,
                'account_name' => 'User New',
                'account_number' => '222000222',
            ])
            ->assertStatus(201)
            ->assertJsonPath('errors', null);
    }
}
