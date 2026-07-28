<?php

namespace Tests\Feature\Settlement;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Bet;
use App\Models\SettlementReversal;
use App\Models\TwoDResult;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Bet\BetPayoutService;
use App\Services\Bet\BetSettlementService;
use App\Services\Bet\SettlementReversalService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementRevertServiceTest extends TestCase
{
    use RefreshDatabase;

    private function settleWrongNumber(
        User $winner,
        User $loser,
        string $historyId = 'history-wrong',
        int $walletBalance = 50_000
    ): array {
        Wallet::factory()->create([
            'user_id' => $winner->id,
            'balance' => $walletBalance,
            'currency' => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        Wallet::factory()->create([
            'user_id' => $loser->id,
            'balance' => 10_000,
            'currency' => Currency::MMK,
            'currency_locked_at' => now(),
        ]);

        $wonBet = Bet::factory()->for($winner)->create([
            'bet_type' => BetType::TWO_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::OPEN,
            'target_opentime' => '12:01:00',
            'stock_date' => '2026-06-01',
        ]);
        $wonBet->betNumbers()->create([
            'number' => 12,
            'amount' => 1_000,
            'odd' => 85,
            'potential_winning' => 85_000,
        ]);

        $lostBet = Bet::factory()->for($loser)->create([
            'bet_type' => BetType::TWO_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::OPEN,
            'target_opentime' => '12:01:00',
            'stock_date' => '2026-06-01',
        ]);
        $lostBet->betNumbers()->create([
            'number' => 34,
            'amount' => 2_000,
            'odd' => 85,
            'potential_winning' => 170_000,
        ]);

        $result = TwoDResult::query()->create([
            'history_id' => $historyId,
            'stock_date' => '2026-06-01',
            'stock_datetime' => '2026-06-01 12:01:00',
            'open_time' => '12:01:00',
            'twod' => '12',
            'payload' => [],
        ]);

        app(BetSettlementService::class)->settleTwoDResult($result);

        // Under the approval gate, settlement no longer pays. Approve the winner's
        // payout so there is a real credit for the revert to claw back.
        $payoutAdmin = User::factory()->admin()->create();
        app(BetPayoutService::class)->approve($wonBet->refresh(), (string) $payoutAdmin->id);

        return [$wonBet, $lostBet, $result];
    }

    public function test_revert_resets_bets_debits_wallet_and_deletes_run(): void
    {
        $admin = User::factory()->admin()->create();
        $winner = User::factory()->normalUser()->create();
        $loser = User::factory()->normalUser()->create();

        [$wonBet, $lostBet] = $this->settleWrongNumber($winner, $loser, 'history-revert-happy', 100_000);

        $reversal = app(SettlementReversalService::class)
            ->revert('history-revert-happy', $admin->id, 'Wrong number entered');

        $this->assertSame(SettlementReversal::STATUS_COMPLETED, $reversal->status);
        $this->assertSame(85_000, $reversal->total_debited);
        $this->assertSame(0, $reversal->total_shortfall);
        $this->assertSame(2, $reversal->summary['bets_reverted']);
        $this->assertSame(1, $reversal->summary['winners_reversed']);
        $this->assertSame(1, $reversal->summary['losers_reset']);

        foreach ([$wonBet, $lostBet] as $bet) {
            $this->assertDatabaseHas('bets', [
                'id' => $bet->id,
                'bet_result_status' => BetResultStatus::OPEN->value,
                'payout_status' => BetPayoutStatus::PENDING->value,
                'settled_result_history_id' => null,
                'settled_at' => null,
                'paid_out_at' => null,
            ]);
        }

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $winner->id,
            'type' => WalletTransactionType::BET_WIN_REVERSAL->value,
            'direction' => WalletTransactionDirection::DEBIT->value,
            'amount' => 85_000,
            'note' => 'Settlement reversal: history-revert-happy',
        ]);

        // Winner started at 100k, was credited 85k (=185k), then debited 85k back.
        $this->assertSame(100_000, (int) Wallet::query()->where('user_id', $winner->id)->value('balance'));

        $this->assertDatabaseMissing('bet_settlement_runs', ['history_id' => 'history-revert-happy']);

        $this->assertDatabaseHas('settlement_reversal_items', [
            'settlement_reversal_id' => $reversal->id,
            'bet_id' => $wonBet->id,
            'paid_amount' => 85_000,
            'debited_amount' => 85_000,
            'shortfall_amount' => 0,
        ]);
        $this->assertDatabaseHas('settlement_reversal_items', [
            'settlement_reversal_id' => $reversal->id,
            'bet_id' => $lostBet->id,
            'paid_amount' => 0,
            'debited_amount' => 0,
        ]);
    }

    public function test_revert_with_insufficient_balance_records_shortfall(): void
    {
        $admin = User::factory()->admin()->create();
        $winner = User::factory()->normalUser()->create();
        $loser = User::factory()->normalUser()->create();

        // Winner starts with zero; after credit has 85k, then "spends" 60k.
        [$wonBet] = $this->settleWrongNumber($winner, $loser, 'history-revert-short', 0);
        Wallet::query()->where('user_id', $winner->id)->update(['balance' => 25_000]);

        $reversal = app(SettlementReversalService::class)
            ->revert('history-revert-short', $admin->id, 'Wrong number entered');

        $this->assertSame(25_000, $reversal->total_debited);
        $this->assertSame(60_000, $reversal->total_shortfall);

        $this->assertDatabaseHas('settlement_reversal_items', [
            'settlement_reversal_id' => $reversal->id,
            'bet_id' => $wonBet->id,
            'paid_amount' => 85_000,
            'debited_amount' => 25_000,
            'shortfall_amount' => 60_000,
        ]);

        $this->assertSame(0, (int) Wallet::query()->where('user_id', $winner->id)->value('balance'));
    }

    public function test_revert_with_zero_balance_records_full_shortfall(): void
    {
        $admin = User::factory()->admin()->create();
        $winner = User::factory()->normalUser()->create();
        $loser = User::factory()->normalUser()->create();

        [$wonBet] = $this->settleWrongNumber($winner, $loser, 'history-revert-zero', 0);
        Wallet::query()->where('user_id', $winner->id)->update(['balance' => 0]);

        $reversal = app(SettlementReversalService::class)
            ->revert('history-revert-zero', $admin->id, 'Wrong number entered');

        $this->assertSame(0, $reversal->total_debited);
        $this->assertSame(85_000, $reversal->total_shortfall);

        $this->assertDatabaseHas('settlement_reversal_items', [
            'bet_id' => $wonBet->id,
            'debited_amount' => 0,
            'shortfall_amount' => 85_000,
        ]);

        $this->assertDatabaseMissing('wallet_transactions', [
            'type' => WalletTransactionType::BET_WIN_REVERSAL->value,
            'reference_id' => $wonBet->id,
        ]);
    }

    public function test_revert_restores_potential_winning_from_original_odd(): void
    {
        $admin = User::factory()->admin()->create();
        $winner = User::factory()->normalUser()->create();
        $loser = User::factory()->normalUser()->create();

        [$wonBet] = $this->settleWrongNumber($winner, $loser, 'history-revert-tempodd', 200_000);

        // Simulate a temp-odd rewrite that happened during settlement.
        $wonBet->betNumbers()->update(['potential_winning' => 120_000]);

        app(SettlementReversalService::class)
            ->revert('history-revert-tempodd', $admin->id, 'Wrong number entered');

        $this->assertDatabaseHas('bet_numbers', [
            'bet_id' => $wonBet->id,
            'number' => 12,
            'potential_winning' => '85000.00', // amount 1000 * odd 85
        ]);
    }

    public function test_revert_without_completed_run_throws(): void
    {
        $admin = User::factory()->admin()->create();

        $this->expectException(DomainException::class);

        app(SettlementReversalService::class)->revert('missing-history', $admin->id, 'nope');
    }

    public function test_double_revert_throws_after_first_completes(): void
    {
        $admin = User::factory()->admin()->create();
        $winner = User::factory()->normalUser()->create();
        $loser = User::factory()->normalUser()->create();

        $this->settleWrongNumber($winner, $loser, 'history-revert-double', 100_000);

        app(SettlementReversalService::class)
            ->revert('history-revert-double', $admin->id, 'Wrong number entered');

        $this->expectException(DomainException::class);

        app(SettlementReversalService::class)
            ->revert('history-revert-double', $admin->id, 'again');
    }

    public function test_revert_then_resettle_with_correct_number_pays_new_winner(): void
    {
        $admin = User::factory()->admin()->create();
        $winner = User::factory()->normalUser()->create();
        $loser = User::factory()->normalUser()->create();

        // "12" settled wrongly: $winner paid 85k. Real number is "34" ($loser's pick).
        [$wonBet, $lostBet, $result] = $this->settleWrongNumber($winner, $loser, 'history-revert-cycle', 100_000);

        app(SettlementReversalService::class)
            ->revert('history-revert-cycle', $admin->id, 'API glitch, real number is 34');

        $result->update(['twod' => '34']);
        $summary = app(BetSettlementService::class)->settleTwoDResult($result->refresh());

        $this->assertSame(2, $summary['settled']);
        $this->assertSame(1, $summary['won']);

        // Re-settle leaves the correct winner WON+PENDING; admin approves the payout.
        app(BetPayoutService::class)->approve($lostBet->refresh(), (string) $admin->id);

        $this->assertDatabaseHas('bets', [
            'id' => $lostBet->id,
            'bet_result_status' => BetResultStatus::WON->value,
            'payout_status' => BetPayoutStatus::PAID_OUT->value,
            'settled_result_history_id' => 'history-revert-cycle',
        ]);
        $this->assertDatabaseHas('bets', [
            'id' => $wonBet->id,
            'bet_result_status' => BetResultStatus::LOST->value,
        ]);

        // Wrong winner net: +85k then -85k → back to start.
        $this->assertSame(100_000, (int) Wallet::query()->where('user_id', $winner->id)->value('balance'));
        // Right winner: 10k + 170k payout.
        $this->assertSame(180_000, (int) Wallet::query()->where('user_id', $loser->id)->value('balance'));
    }
}
