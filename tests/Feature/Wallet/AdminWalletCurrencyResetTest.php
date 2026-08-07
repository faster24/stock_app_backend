<?php

namespace Tests\Feature\Wallet;

use App\Enums\BankName;
use App\Enums\Currency;
use App\Models\Bet;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWalletCurrencyResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reset_currency_on_an_untouched_wallet(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        Wallet::factory()->create([
            'user_id'              => $user->id,
            'balance'              => 0,
            'currency'             => Currency::THB,
            'currency_locked_at'   => now(),
            'bank_name'            => BankName::SCB,
            'account_name'         => 'Wrong Setup',
            'account_number'       => '405-889-1034',
            'bank_info_updated_at' => now(),
        ]);
        $adminToken = $admin->createToken('test')->plainTextToken;

        $response = $this->postJson("/api/v1/admin/users/{$user->id}/wallet/reset-currency", [],
            ['Authorization' => "Bearer $adminToken"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.wallet.currency', null);
        $response->assertJsonPath('data.wallet.currency_locked_at', null);
        $response->assertJsonPath('data.wallet.bank_name', null);

        $this->assertDatabaseHas('wallets', [
            'user_id'              => $user->id,
            'currency'             => null,
            'currency_locked_at'   => null,
            'bank_info_updated_at' => null,
        ]);
    }

    public function test_reset_is_refused_when_the_wallet_holds_a_balance(): void
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

        $this->postJson("/api/v1/admin/users/{$user->id}/wallet/reset-currency", [],
            ['Authorization' => "Bearer $adminToken"]
        )->assertStatus(422);

        $this->assertDatabaseHas('wallets', [
            'user_id'  => $user->id,
            'currency' => Currency::MMK->value,
        ]);
    }

    public function test_reset_is_refused_once_the_user_has_placed_bets(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        Wallet::factory()->create([
            'user_id'            => $user->id,
            'balance'            => 0,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        Bet::factory()->create(['user_id' => $user->id]);
        $adminToken = $admin->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/admin/users/{$user->id}/wallet/reset-currency", [],
            ['Authorization' => "Bearer $adminToken"]
        )->assertStatus(422);
    }

    public function test_force_overrides_the_guards(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        Wallet::factory()->create([
            'user_id'            => $user->id,
            'balance'            => 25_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        Bet::factory()->create(['user_id' => $user->id]);
        $adminToken = $admin->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/admin/users/{$user->id}/wallet/reset-currency", ['force' => true],
            ['Authorization' => "Bearer $adminToken"]
        )->assertStatus(200)->assertJsonPath('data.wallet.currency', null);
    }

    public function test_reset_returns_422_when_the_user_has_no_wallet(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $adminToken = $admin->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/admin/users/{$user->id}/wallet/reset-currency", [],
            ['Authorization' => "Bearer $adminToken"]
        )->assertStatus(422);
    }

    public function test_non_admin_cannot_reset_currency(): void
    {
        $user = User::factory()->normalUser()->create();
        $target = User::factory()->create();
        Wallet::factory()->create([
            'user_id'            => $target->id,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/admin/users/{$target->id}/wallet/reset-currency", [],
            ['Authorization' => "Bearer $token"]
        )->assertStatus(403);
    }

    public function test_guest_cannot_reset_currency(): void
    {
        $target = User::factory()->create();

        $this->postJson("/api/v1/admin/users/{$target->id}/wallet/reset-currency")->assertStatus(401);
    }

    public function test_user_can_choose_a_currency_again_after_a_reset(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        Wallet::factory()->create([
            'user_id'            => $user->id,
            'balance'            => 0,
            'currency'           => Currency::THB,
            'currency_locked_at' => now(),
        ]);
        $adminToken = $admin->createToken('test')->plainTextToken;
        $userToken = $user->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/admin/users/{$user->id}/wallet/reset-currency", [],
            ['Authorization' => "Bearer $adminToken"]
        )->assertStatus(200);

        // Drop the admin's resolved guard, otherwise the next request is still
        // attributed to them and the currency lands on the wrong wallet.
        $this->app['auth']->forgetGuards();

        $this->putJson('/api/v1/me/wallet/currency', ['currency' => 'MMK'],
            ['Authorization' => "Bearer $userToken"]
        )->assertStatus(200);

        $this->assertDatabaseHas('wallets', [
            'user_id'  => $user->id,
            'currency' => Currency::MMK->value,
        ]);
    }
}
