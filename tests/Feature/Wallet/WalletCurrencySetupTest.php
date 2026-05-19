<?php

namespace Tests\Feature\Wallet;

use App\Enums\Currency;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletCurrencySetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_set_currency_once(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->putJson('/api/v1/me/wallet/currency',
            ['currency' => 'MMK'],
            ['Authorization' => "Bearer $token"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.wallet.currency', 'MMK');
        $response->assertJsonPath('data.wallet.balance', 0);
        $this->assertDatabaseHas('wallets', [
            'user_id'  => $user->id,
            'currency' => 'MMK',
        ]);
    }

    public function test_second_set_attempt_returns_422(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create([
            'user_id'            => $user->id,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->putJson('/api/v1/me/wallet/currency',
            ['currency' => 'THB'],
            ['Authorization' => "Bearer $token"]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('errors.currency.0', 'Wallet currency is already set and cannot be changed.');
    }

    public function test_invalid_currency_value_returns_422(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->putJson('/api/v1/me/wallet/currency',
            ['currency' => 'USD'],
            ['Authorization' => "Bearer $token"]
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['currency']]);
    }

    public function test_creates_wallet_row_if_missing(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->assertDatabaseMissing('wallets', ['user_id' => $user->id]);

        $this->putJson('/api/v1/me/wallet/currency',
            ['currency' => 'MMK'],
            ['Authorization' => "Bearer $token"]
        )->assertStatus(200);

        $this->assertDatabaseHas('wallets', [
            'user_id'  => $user->id,
            'currency' => 'MMK',
            'balance'  => 0,
        ]);
    }

    public function test_existing_wallet_without_currency_gets_currency_set(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create([
            'user_id'  => $user->id,
            'balance'  => 5_000,
            'currency' => null,
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->putJson('/api/v1/me/wallet/currency',
            ['currency' => 'THB'],
            ['Authorization' => "Bearer $token"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.wallet.currency', 'THB');
        $response->assertJsonPath('data.wallet.balance', 5_000);
    }

    public function test_currency_locked_at_is_set_on_success(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->putJson('/api/v1/me/wallet/currency',
            ['currency' => 'MMK'],
            ['Authorization' => "Bearer $token"]
        )->assertStatus(200);

        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertNotNull($wallet->currency_locked_at);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->putJson('/api/v1/me/wallet/currency', ['currency' => 'MMK'])
            ->assertStatus(401);
    }
}
