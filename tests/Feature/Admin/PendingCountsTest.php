<?php

namespace Tests\Feature\Admin;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\WithdrawalStatus;
use App\Models\AdminBankSetting;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingCountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->user = User::factory()->normalUser()->create();
    }

    private function actingAsAdmin(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->admin->createToken('test')->plainTextToken);
    }

    private function makeDeposit(DepositStatus $status): Deposit
    {
        return Deposit::create([
            'user_id' => $this->user->id,
            'admin_bank_setting_id' => AdminBankSetting::factory()->create(['currency' => Currency::MMK])->id,
            'currency' => Currency::MMK->value,
            'claimed_amount' => 10_000,
            'status' => $status->value,
        ]);
    }

    private function makeWithdrawal(WithdrawalStatus $status): Withdrawal
    {
        return Withdrawal::create([
            'user_id' => $this->user->id,
            'currency' => Currency::MMK->value,
            'amount' => 10_000,
            'status' => $status->value,
            'bank_snapshot' => ['bank_name' => 'KBZ', 'account_name' => 'A', 'account_number' => '1'],
        ]);
    }

    private function makeBet(BetResultStatus $result, BetPayoutStatus $payout): Bet
    {
        return Bet::factory()->create([
            'user_id' => $this->user->id,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => $result,
            'payout_status' => $payout,
        ]);
    }

    public function test_guest_cannot_read_pending_counts(): void
    {
        $this->getJson('/api/v1/admin/pending-counts')->assertStatus(401);
    }

    public function test_non_admin_cannot_read_pending_counts(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->user->createToken('test')->plainTextToken)
            ->getJson('/api/v1/admin/pending-counts')
            ->assertStatus(403);
    }

    public function test_counts_only_the_three_open_queues(): void
    {
        $this->makeDeposit(DepositStatus::PENDING);
        $this->makeDeposit(DepositStatus::PENDING);
        $this->makeDeposit(DepositStatus::APPROVED);

        $this->makeWithdrawal(WithdrawalStatus::PENDING);
        $this->makeWithdrawal(WithdrawalStatus::REJECTED);

        // Only a settled winner still awaiting its payout is admin work.
        $this->makeBet(BetResultStatus::WON, BetPayoutStatus::PENDING);
        $this->makeBet(BetResultStatus::WON, BetPayoutStatus::PAID_OUT);
        $this->makeBet(BetResultStatus::LOST, BetPayoutStatus::PENDING);
        $this->makeBet(BetResultStatus::OPEN, BetPayoutStatus::PENDING);

        $this->actingAsAdmin()
            ->getJson('/api/v1/admin/pending-counts')
            ->assertOk()
            ->assertJsonPath('data.deposits', 2)
            ->assertJsonPath('data.withdrawals', 1)
            ->assertJsonPath('data.bets', 1)
            ->assertJsonPath('data.total', 4);
    }

    public function test_empty_system_reports_zeroes(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/v1/admin/pending-counts')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }
}
