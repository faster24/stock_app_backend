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

class AdminWalletReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_any_user_wallet(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        Wallet::factory()->create([
            'user_id'            => $user->id,
            'balance'            => 25_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        $adminToken = $admin->createToken('test')->plainTextToken;

        $response = $this->getJson("/api/v1/admin/users/{$user->id}/wallet",
            ['Authorization' => "Bearer $adminToken"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.wallet.balance', 25_000);
        $response->assertJsonPath('data.wallet.currency', 'MMK');
    }

    public function test_admin_returns_404_for_user_with_no_wallet(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $adminToken = $admin->createToken('test')->plainTextToken;

        $this->getJson("/api/v1/admin/users/{$user->id}/wallet",
            ['Authorization' => "Bearer $adminToken"]
        )->assertStatus(404);
    }

    public function test_admin_can_view_user_transactions(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        Wallet::factory()->create([
            'user_id'            => $user->id,
            'balance'            => 10_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        $adminToken = $admin->createToken('test')->plainTextToken;

        $mutator = new WalletMutator();
        $mutator->mutate(
            userId: $user->id,
            type: WalletTransactionType::DEPOSIT,
            direction: WalletTransactionDirection::CREDIT,
            amount: 5_000,
            reference: null,
            createdByUserId: $admin->id,
        );

        $response = $this->getJson("/api/v1/admin/users/{$user->id}/wallet/transactions",
            ['Authorization' => "Bearer $adminToken"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.total', 1);
    }

    public function test_non_admin_cannot_view_other_user_wallet(): void
    {
        $user1 = User::factory()->normalUser()->create();
        $user2 = User::factory()->create();
        Wallet::factory()->create([
            'user_id'            => $user2->id,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        $token = $user1->createToken('test')->plainTextToken;

        $this->getJson("/api/v1/admin/users/{$user2->id}/wallet",
            ['Authorization' => "Bearer $token"]
        )->assertStatus(403);
    }

    public function test_non_admin_cannot_view_user_transactions(): void
    {
        $user1 = User::factory()->normalUser()->create();
        $user2 = User::factory()->create();
        Wallet::factory()->create([
            'user_id'            => $user2->id,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        $token = $user1->createToken('test')->plainTextToken;

        $this->getJson("/api/v1/admin/users/{$user2->id}/wallet/transactions",
            ['Authorization' => "Bearer $token"]
        )->assertStatus(403);
    }

    public function test_guest_cannot_access_admin_wallet_endpoints(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/v1/admin/users/{$user->id}/wallet")->assertStatus(401);
        $this->getJson("/api/v1/admin/users/{$user->id}/wallet/transactions")->assertStatus(401);
    }
}
