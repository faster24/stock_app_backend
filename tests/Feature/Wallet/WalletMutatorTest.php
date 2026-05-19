<?php

namespace Tests\Feature\Wallet;

use App\Enums\Currency;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletMutator;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletMutatorTest extends TestCase
{
    use RefreshDatabase;

    private WalletMutator $mutator;
    private User $user;
    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mutator = new WalletMutator();
        $this->user = User::factory()->create();
        $this->wallet = Wallet::factory()->create([
            'user_id'            => $this->user->id,
            'balance'            => 10_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
    }

    public function test_credit_increments_balance_and_writes_ledger(): void
    {
        $txn = $this->mutator->mutate(
            userId: $this->user->id,
            type: WalletTransactionType::DEPOSIT,
            direction: WalletTransactionDirection::CREDIT,
            amount: 5_000,
            reference: null,
            createdByUserId: $this->user->id,
            note: 'test deposit',
        );

        $this->wallet->refresh();

        $this->assertEquals(15_000, $this->wallet->balance);
        $this->assertEquals(15_000, $txn->balance_after);
        $this->assertEquals(5_000, $txn->amount);
        $this->assertEquals(WalletTransactionDirection::CREDIT, $txn->direction);
        $this->assertEquals(WalletTransactionType::DEPOSIT, $txn->type);
        $this->assertEquals($this->wallet->id, $txn->wallet_id);
        $this->assertEquals($this->user->id, $txn->user_id);
        $this->assertEquals('test deposit', $txn->note);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'      => $this->user->id,
            'amount'       => 5_000,
            'balance_after' => 15_000,
            'currency'     => 'MMK',
        ]);
    }

    public function test_debit_reduces_balance_correctly(): void
    {
        $txn = $this->mutator->mutate(
            userId: $this->user->id,
            type: WalletTransactionType::BET_PLACE,
            direction: WalletTransactionDirection::DEBIT,
            amount: 3_000,
            reference: null,
            createdByUserId: $this->user->id,
        );

        $this->wallet->refresh();

        $this->assertEquals(7_000, $this->wallet->balance);
        $this->assertEquals(7_000, $txn->balance_after);
        $this->assertEquals(WalletTransactionDirection::DEBIT, $txn->direction);
    }

    public function test_debit_below_zero_throws_and_writes_nothing(): void
    {
        try {
            $this->mutator->mutate(
                userId: $this->user->id,
                type: WalletTransactionType::BET_PLACE,
                direction: WalletTransactionDirection::DEBIT,
                amount: 99_999,
                reference: null,
                createdByUserId: $this->user->id,
            );

            $this->fail('Expected DomainException was not thrown.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Insufficient balance', $e->getMessage());
        }

        $this->wallet->refresh();
        $this->assertEquals(10_000, $this->wallet->balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_unset_currency_throws(): void
    {
        $userNoCurrency = User::factory()->create();
        Wallet::factory()->create([
            'user_id'  => $userNoCurrency->id,
            'balance'  => 5_000,
            'currency' => null,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Wallet currency is not set.');

        $this->mutator->mutate(
            userId: $userNoCurrency->id,
            type: WalletTransactionType::DEPOSIT,
            direction: WalletTransactionDirection::CREDIT,
            amount: 1_000,
            reference: null,
            createdByUserId: $userNoCurrency->id,
        );
    }

    public function test_zero_amount_throws(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Wallet mutation amount must be positive.');

        $this->mutator->mutate(
            userId: $this->user->id,
            type: WalletTransactionType::DEPOSIT,
            direction: WalletTransactionDirection::CREDIT,
            amount: 0,
            reference: null,
            createdByUserId: $this->user->id,
        );
    }

    public function test_negative_amount_throws(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Wallet mutation amount must be positive.');

        $this->mutator->mutate(
            userId: $this->user->id,
            type: WalletTransactionType::DEPOSIT,
            direction: WalletTransactionDirection::CREDIT,
            amount: -500,
            reference: null,
            createdByUserId: $this->user->id,
        );
    }

    public function test_wallet_not_found_throws(): void
    {
        $userNoWallet = User::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Wallet not found.');

        $this->mutator->mutate(
            userId: $userNoWallet->id,
            type: WalletTransactionType::DEPOSIT,
            direction: WalletTransactionDirection::CREDIT,
            amount: 1_000,
            reference: null,
            createdByUserId: $userNoWallet->id,
        );
    }

    public function test_polymorphic_reference_stored_correctly(): void
    {
        $refUser = User::factory()->create();

        $txn = $this->mutator->mutate(
            userId: $this->user->id,
            type: WalletTransactionType::ADJUSTMENT,
            direction: WalletTransactionDirection::CREDIT,
            amount: 1_000,
            reference: $refUser,
            createdByUserId: $this->user->id,
        );

        $this->assertEquals(User::class, $txn->reference_type);
        $this->assertEquals($refUser->id, $txn->reference_id);

        $this->assertDatabaseHas('wallet_transactions', [
            'reference_type' => User::class,
            'reference_id'   => $refUser->id,
        ]);
    }

    public function test_null_reference_stored_as_null(): void
    {
        $txn = $this->mutator->mutate(
            userId: $this->user->id,
            type: WalletTransactionType::ADJUSTMENT,
            direction: WalletTransactionDirection::CREDIT,
            amount: 500,
            reference: null,
            createdByUserId: $this->user->id,
        );

        $this->assertNull($txn->reference_type);
        $this->assertNull($txn->reference_id);
    }

    public function test_exact_balance_debit_succeeds(): void
    {
        $txn = $this->mutator->mutate(
            userId: $this->user->id,
            type: WalletTransactionType::WITHDRAWAL,
            direction: WalletTransactionDirection::DEBIT,
            amount: 10_000,
            reference: null,
            createdByUserId: $this->user->id,
        );

        $this->wallet->refresh();
        $this->assertEquals(0, $this->wallet->balance);
        $this->assertEquals(0, $txn->balance_after);
    }
}
