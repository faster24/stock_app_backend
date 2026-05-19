<?php

namespace Tests\Feature\Wallet;

use App\Enums\Currency;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletMutator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_wallet_returns_balance_and_currency(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create([
            'user_id'            => $user->id,
            'balance'            => 50_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->getJson('/api/v1/me/wallet', ['Authorization' => "Bearer $token"]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.wallet.balance', 50_000);
        $response->assertJsonPath('data.wallet.currency', 'MMK');
        $response->assertJsonStructure(['data' => ['wallet' => ['id', 'balance', 'currency', 'currency_locked_at']]]);
    }

    public function test_me_wallet_returns_404_when_no_wallet(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/me/wallet', ['Authorization' => "Bearer $token"])
            ->assertStatus(404);
    }

    public function test_me_wallet_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/me/wallet')->assertStatus(401);
    }

    public function test_me_wallet_transactions_paginates(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create([
            'user_id'            => $user->id,
            'balance'            => 100_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $mutator = new WalletMutator();
        for ($i = 0; $i < 5; $i++) {
            $mutator->mutate(
                userId: $user->id,
                type: WalletTransactionType::DEPOSIT,
                direction: WalletTransactionDirection::CREDIT,
                amount: 1_000,
                reference: null,
                createdByUserId: $user->id,
            );
        }

        $response = $this->getJson('/api/v1/me/wallet/transactions?page_size=3',
            ['Authorization' => "Bearer $token"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.total', 5);
        $response->assertJsonPath('data.pagination.per_page', 3);
        $response->assertJsonCount(3, 'data.transactions');
    }

    public function test_me_wallet_transactions_filters_by_type(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create([
            'user_id'            => $user->id,
            'balance'            => 100_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $mutator = new WalletMutator();
        $mutator->mutate(userId: $user->id, type: WalletTransactionType::DEPOSIT, direction: WalletTransactionDirection::CREDIT, amount: 1_000, reference: null, createdByUserId: $user->id);
        $mutator->mutate(userId: $user->id, type: WalletTransactionType::ADJUSTMENT, direction: WalletTransactionDirection::CREDIT, amount: 500, reference: null, createdByUserId: $user->id);

        $response = $this->getJson('/api/v1/me/wallet/transactions?type=DEPOSIT',
            ['Authorization' => "Bearer $token"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.total', 1);
        $response->assertJsonPath('data.transactions.0.type', 'DEPOSIT');
    }

    public function test_me_wallet_transactions_only_returns_own(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Wallet::factory()->create([
            'user_id'            => $user1->id,
            'balance'            => 10_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        Wallet::factory()->create([
            'user_id'            => $user2->id,
            'balance'            => 10_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        $token1 = $user1->createToken('test')->plainTextToken;

        $mutator = new WalletMutator();
        $mutator->mutate(userId: $user1->id, type: WalletTransactionType::DEPOSIT, direction: WalletTransactionDirection::CREDIT, amount: 1_000, reference: null, createdByUserId: $user1->id);
        $mutator->mutate(userId: $user2->id, type: WalletTransactionType::DEPOSIT, direction: WalletTransactionDirection::CREDIT, amount: 2_000, reference: null, createdByUserId: $user2->id);

        $response = $this->getJson('/api/v1/me/wallet/transactions',
            ['Authorization' => "Bearer $token1"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.total', 1);
    }
}
