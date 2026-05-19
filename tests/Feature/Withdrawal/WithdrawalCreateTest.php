<?php

namespace Tests\Feature\Withdrawal;

use App\Enums\Currency;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user  = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;
        $this->wallet = Wallet::factory()->create([
            'user_id'            => $this->user->id,
            'balance'            => 50_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
            'bank_name'          => 'KBZ',
            'account_name'       => 'Test User',
            'account_number'     => '1234567890',
        ]);
    }

    public function test_user_can_submit_when_balance_sufficient(): void
    {
        $response = $this->postJson('/api/v1/withdrawals',
            ['currency' => 'MMK', 'amount' => 20_000],
            ['Authorization' => "Bearer {$this->token}"]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.withdrawal.status', 'PENDING');
        $response->assertJsonPath('data.withdrawal.amount', 20_000);

        $this->wallet->refresh();
        $this->assertEquals(30_000, $this->wallet->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'   => $this->user->id,
            'type'      => 'WITHDRAWAL',
            'direction' => 'DEBIT',
            'amount'    => 20_000,
        ]);
    }

    public function test_insufficient_balance_returns_409_no_row_persisted(): void
    {
        $response = $this->postJson('/api/v1/withdrawals',
            ['currency' => 'MMK', 'amount' => 99_999],
            ['Authorization' => "Bearer {$this->token}"]
        );

        $response->assertStatus(409);
        $this->assertDatabaseCount('withdrawals', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);

        $this->wallet->refresh();
        $this->assertEquals(50_000, $this->wallet->balance);
    }

    public function test_requires_complete_bank_info(): void
    {
        $userNoBankInfo = User::factory()->create();
        Wallet::factory()->create([
            'user_id'            => $userNoBankInfo->id,
            'balance'            => 10_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
            'bank_name'          => null,
            'account_name'       => null,
            'account_number'     => null,
        ]);
        $token = $userNoBankInfo->createToken('test')->plainTextToken;

        $this->postJson('/api/v1/withdrawals',
            ['currency' => 'MMK', 'amount' => 1_000],
            ['Authorization' => "Bearer $token"]
        )->assertStatus(422)->assertJsonStructure(['errors' => ['bank_info']]);
    }

    public function test_only_one_pending_at_a_time(): void
    {
        $this->postJson('/api/v1/withdrawals',
            ['currency' => 'MMK', 'amount' => 5_000],
            ['Authorization' => "Bearer {$this->token}"]
        )->assertStatus(201);

        $this->postJson('/api/v1/withdrawals',
            ['currency' => 'MMK', 'amount' => 5_000],
            ['Authorization' => "Bearer {$this->token}"]
        )->assertStatus(409);

        $this->assertDatabaseCount('withdrawals', 1);
    }

    public function test_bank_snapshot_captures_current_info(): void
    {
        $response = $this->postJson('/api/v1/withdrawals',
            ['currency' => 'MMK', 'amount' => 1_000],
            ['Authorization' => "Bearer {$this->token}"]
        );

        $response->assertStatus(201);
        $snapshot = $response->json('data.withdrawal.bank_snapshot');

        $this->assertEquals('KBZ', $snapshot['bank_name']);
        $this->assertEquals('Test User', $snapshot['account_name']);
        $this->assertEquals('1234567890', $snapshot['account_number']);
    }

    public function test_currency_must_match_wallet(): void
    {
        $this->postJson('/api/v1/withdrawals',
            ['currency' => 'THB', 'amount' => 1_000],
            ['Authorization' => "Bearer {$this->token}"]
        )->assertStatus(422)->assertJsonStructure(['errors' => ['currency']]);
    }

    public function test_wallet_currency_unset_returns_422(): void
    {
        $userNoCurrency = User::factory()->create();
        Wallet::factory()->create([
            'user_id'  => $userNoCurrency->id,
            'balance'  => 10_000,
            'currency' => null,
        ]);
        $token = $userNoCurrency->createToken('test')->plainTextToken;

        $this->postJson('/api/v1/withdrawals',
            ['currency' => 'MMK', 'amount' => 1_000],
            ['Authorization' => "Bearer $token"]
        )->assertStatus(422)->assertJsonStructure(['errors' => ['wallet_currency']]);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/v1/withdrawals', [])->assertStatus(401);
    }
}
