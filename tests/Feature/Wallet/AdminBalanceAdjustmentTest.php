<?php

namespace Tests\Feature\Wallet;

use App\Enums\Currency;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBalanceAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_adjust_balance(): void
    {
        $user = User::factory()->normalUser()->create();

        $this->postJson("/api/v1/admin/users/{$user->id}/balance-adjustment", [
            'direction' => 'CREDIT',
            'amount'    => 1000,
            'reason'    => 'Test adjustment reason',
        ])
            ->assertStatus(401)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');
    }

    public function test_non_admin_cannot_adjust_balance(): void
    {
        $actor = User::factory()->normalUser()->create();
        $target = User::factory()->normalUser()->create();
        $token = $actor->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/admin/users/{$target->id}/balance-adjustment", [
                'direction' => 'CREDIT',
                'amount'    => 1000,
                'reason'    => 'Test adjustment reason',
            ])
            ->assertStatus(403)
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');
    }

    public function test_admin_can_credit_user_balance(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $user = User::factory()->normalUser()->create();
        $wallet = Wallet::factory()->create([
            'user_id'            => $user->id,
            'balance'            => 10_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/admin/users/{$user->id}/balance-adjustment", [
                'direction' => 'CREDIT',
                'amount'    => 5_000,
                'reason'    => 'Compensation for system error',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Balance adjusted successfully.')
            ->assertJsonPath('data.transaction.user_id', $user->id)
            ->assertJsonPath('data.transaction.type', WalletTransactionType::ADJUSTMENT->value)
            ->assertJsonPath('data.transaction.direction', WalletTransactionDirection::CREDIT->value)
            ->assertJsonPath('data.transaction.amount', 5_000)
            ->assertJsonPath('data.transaction.balance_after', 15_000)
            ->assertJsonPath('errors', null);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'            => $user->id,
            'type'               => WalletTransactionType::ADJUSTMENT->value,
            'direction'          => WalletTransactionDirection::CREDIT->value,
            'amount'             => 5_000,
            'balance_after'      => 15_000,
            'created_by_user_id' => $admin->id,
            'note'               => 'Compensation for system error',
        ]);

        $wallet->refresh();
        $this->assertSame(15_000, (int) $wallet->balance);
    }

    public function test_admin_can_debit_user_balance(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $user = User::factory()->normalUser()->create();
        $wallet = Wallet::factory()->create([
            'user_id'            => $user->id,
            'balance'            => 10_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/admin/users/{$user->id}/balance-adjustment", [
                'direction' => 'DEBIT',
                'amount'    => 3_000,
                'reason'    => 'Manual correction for over-credit',
            ])
            ->assertOk()
            ->assertJsonPath('data.transaction.direction', WalletTransactionDirection::DEBIT->value)
            ->assertJsonPath('data.transaction.amount', 3_000)
            ->assertJsonPath('data.transaction.balance_after', 7_000);

        $wallet->refresh();
        $this->assertSame(7_000, (int) $wallet->balance);
    }

    public function test_debit_beyond_balance_returns_409(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $user = User::factory()->normalUser()->create();
        Wallet::factory()->create([
            'user_id'            => $user->id,
            'balance'            => 1_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/admin/users/{$user->id}/balance-adjustment", [
                'direction' => 'DEBIT',
                'amount'    => 5_000,
                'reason'    => 'Manual correction for over-credit',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Insufficient balance.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.balance.0', 'Insufficient balance.');
    }

    public function test_adjustment_validates_required_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;
        $user = User::factory()->normalUser()->create();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/admin/users/{$user->id}/balance-adjustment", [])
            ->assertStatus(422)
            ->assertJsonPath('errors.direction.0', 'The direction field is required.')
            ->assertJsonPath('errors.amount.0', 'The amount field is required.')
            ->assertJsonPath('errors.reason.0', 'The reason field is required.');
    }

    public function test_reason_must_be_at_least_10_characters(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;
        $user = User::factory()->normalUser()->create();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/admin/users/{$user->id}/balance-adjustment", [
                'direction' => 'CREDIT',
                'amount'    => 1000,
                'reason'    => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'The reason field must be at least 10 characters.');
    }

    public function test_wallet_not_found_returns_409(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;
        $user = User::factory()->normalUser()->create();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/admin/users/{$user->id}/balance-adjustment", [
                'direction' => 'CREDIT',
                'amount'    => 1000,
                'reason'    => 'Test adjustment reason',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Wallet not found.')
            ->assertJsonPath('errors.balance.0', 'Wallet not found.');
    }
}
