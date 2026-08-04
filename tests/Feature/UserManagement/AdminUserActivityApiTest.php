<?php

namespace Tests\Feature\UserManagement;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\WithdrawalStatus;
use App\Models\AdminBankSetting;
use App\Models\Bet;
use App\Models\BetNumber;
use App\Models\Deposit;
use App\Models\TwoDResult;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminUserActivityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_user_activity_summary(): void
    {
        $user = User::factory()->normalUser()->create();

        $this->getJson('/api/v1/admin/users/'.$user->id.'/activity-summary')
            ->assertStatus(401);
    }

    public function test_non_admin_cannot_read_user_activity_summary(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/users/'.$user->id.'/activity-summary')
            ->assertStatus(403);
    }

    public function test_activity_summary_returns_404_for_unknown_user(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/users/00000000-0000-0000-0000-000000000000/activity-summary')
            ->assertStatus(404)
            ->assertJsonPath('message', 'User not found.');
    }

    public function test_activity_summary_aggregates_bets_winnings_deposits_and_withdrawals(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $user = User::factory()->normalUser()->create();
        Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 250_000,
            'currency' => Currency::MMK,
        ]);

        $other = User::factory()->normalUser()->create();

        // Paid-out winner: 2 x 1_000 on the drawn number 12 at odds 80.
        $paidOut = $this->createSettledWinner($user, payoutStatus: BetPayoutStatus::PAID_OUT);
        // Winner still awaiting payout.
        $pendingPayout = $this->createSettledWinner($user, payoutStatus: BetPayoutStatus::PENDING);

        // A loser and a rejected bet — the rejected one must not count as staked.
        Bet::factory()->create([
            'user_id' => $user->id,
            'total_amount' => '3000.00',
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::LOST,
        ]);
        Bet::factory()->create([
            'user_id' => $user->id,
            'total_amount' => '9999.00',
            'status' => BetStatus::REJECTED,
        ]);

        // Another user's activity must never leak into the summary.
        Bet::factory()->create([
            'user_id' => $other->id,
            'total_amount' => '5000.00',
            'status' => BetStatus::ACCEPTED,
        ]);

        $bankSetting = AdminBankSetting::factory()->create();
        Deposit::create([
            'user_id' => $user->id,
            'admin_bank_setting_id' => $bankSetting->id,
            'currency' => Currency::MMK,
            'claimed_amount' => 50_000,
            'approved_amount' => 50_000,
            'status' => DepositStatus::APPROVED,
        ]);
        Deposit::create([
            'user_id' => $user->id,
            'admin_bank_setting_id' => $bankSetting->id,
            'currency' => Currency::MMK,
            'claimed_amount' => 10_000,
            'status' => DepositStatus::PENDING,
        ]);

        $this->createWithdrawal($user, 40_000, WithdrawalStatus::COMPLETED);
        $this->createWithdrawal($user, 15_000, WithdrawalStatus::PENDING);
        $this->createWithdrawal($other, 99_000, WithdrawalStatus::COMPLETED);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/users/'.$user->id.'/activity-summary')
            ->assertStatus(200);

        $response->assertJsonPath('data.user.id', $user->id);
        $response->assertJsonPath('data.summary.wallet.balance', 250_000);
        $response->assertJsonPath('data.summary.wallet.currency', 'MMK');

        $response->assertJsonPath('data.summary.bets.total', 4);
        $response->assertJsonPath('data.summary.bets.accepted', 3);
        $response->assertJsonPath('data.summary.bets.rejected', 1);
        // 2 winners at 2_000 each + the 3_000 loser; the rejected bet is excluded.
        $response->assertJsonPath('data.summary.bets.total_staked', 7_000);
        $response->assertJsonPath('data.summary.bets.won', 2);
        $response->assertJsonPath('data.summary.bets.lost', 1);

        $response->assertJsonPath('data.summary.winnings.won_bet_count', 2);
        $response->assertJsonPath('data.summary.winnings.total_won_amount', 320_000);
        $response->assertJsonPath('data.summary.winnings.paid_out_amount', 160_000);
        $response->assertJsonPath('data.summary.winnings.pending_payout_amount', 160_000);
        $response->assertJsonPath('data.summary.winnings.pending_payout_count', 1);

        $response->assertJsonPath('data.summary.deposits.approved_count', 1);
        $response->assertJsonPath('data.summary.deposits.approved_amount', 50_000);
        $response->assertJsonPath('data.summary.deposits.pending_count', 1);

        $response->assertJsonPath('data.summary.withdrawals.completed_count', 1);
        $response->assertJsonPath('data.summary.withdrawals.completed_amount', 40_000);
        $response->assertJsonPath('data.summary.withdrawals.pending_count', 1);
        $response->assertJsonPath('data.summary.withdrawals.pending_amount', 15_000);

        $this->assertNotNull($paidOut->fresh());
        $this->assertNotNull($pendingPayout->fresh());
    }

    public function test_admin_bet_list_can_be_filtered_by_user(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $user = User::factory()->normalUser()->create();
        $other = User::factory()->normalUser()->create();

        $mine = Bet::factory()->create(['user_id' => $user->id]);
        Bet::factory()->create(['user_id' => $other->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/bets?user_id='.$user->id)
            ->assertStatus(200);

        $response->assertJsonCount(1, 'data.bets');
        $response->assertJsonPath('data.bets.0.id', $mine->id);
    }

    public function test_admin_withdrawal_list_can_be_filtered_by_user(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $user = User::factory()->normalUser()->create();
        $other = User::factory()->normalUser()->create();

        $mine = $this->createWithdrawal($user, 20_000, WithdrawalStatus::PENDING);
        $this->createWithdrawal($other, 30_000, WithdrawalStatus::PENDING);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/withdrawals?user_id='.$user->id)
            ->assertStatus(200);

        $response->assertJsonCount(1, 'data.withdrawals');
        $response->assertJsonPath('data.withdrawals.0.id', $mine->id);
        $response->assertJsonPath('data.pagination.total', 1);
    }

    /** A WON 2D bet with 2_000 staked on the drawn number and 160_000 of winnings. */
    private function createSettledWinner(User $user, BetPayoutStatus $payoutStatus): Bet
    {
        $historyId = 'history-'.$user->id.'-'.$payoutStatus->value;

        TwoDResult::create([
            'history_id' => $historyId,
            'stock_date' => '2026-01-01',
            'open_time' => '12:01:00',
            'twod' => '12',
            'payload' => [],
        ]);

        $bet = Bet::factory()->create([
            'user_id' => $user->id,
            'bet_type' => BetType::TWO_D,
            'total_amount' => '2000.00',
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => $payoutStatus,
            'settled_result_history_id' => $historyId,
            'settled_at' => Carbon::parse('2026-01-01 12:05:00'),
        ]);

        // 2_000 on the drawn number 12 at odds 80 → 160_000, plus a losing number.
        BetNumber::factory()->forBetWithNumber($bet, 12, 2_000)->create();
        BetNumber::factory()->forBetWithNumber($bet, 34, 1_000)->create();

        return $bet;
    }

    private function createWithdrawal(User $user, int $amount, WithdrawalStatus $status): Withdrawal
    {
        return Withdrawal::create([
            'user_id' => $user->id,
            'currency' => Currency::MMK,
            'amount' => $amount,
            'status' => $status,
            'bank_snapshot' => ['bank_name' => 'KBZ', 'account_name' => 'Test', 'account_number' => '0000000001'],
        ]);
    }
}
