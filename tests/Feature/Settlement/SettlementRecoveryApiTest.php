<?php

namespace Tests\Feature\Settlement;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\WalletTransactionType;
use App\Models\Bet;
use App\Models\ThreeDResult;
use App\Models\TwoDResult;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Bet\BetPayoutService;
use App\Services\Bet\BetSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementRecoveryApiTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return User::factory()->admin()->create()->createToken('auth_token')->plainTextToken;
    }

    /** Approve a WON+pending bet's payout (settlement no longer auto-pays). */
    private function approvePayout(Bet $bet): void
    {
        $approver = User::factory()->admin()->create();
        app(BetPayoutService::class)->approve($bet->refresh(), (string) $approver->id);
    }

    private function makeOpenBet(User $user, int $number, string $openTime = '12:01:00', string $date = '2026-06-01'): Bet
    {
        Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100_000,
            'currency' => Currency::MMK,
            'currency_locked_at' => now(),
        ]);

        $bet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::OPEN,
            'target_opentime' => $openTime,
            'stock_date' => $date,
        ]);
        $bet->betNumbers()->create([
            'number' => $number,
            'amount' => 1_000,
            'odd' => 85,
            'potential_winning' => 85_000,
        ]);

        return $bet;
    }

    public function test_manual_two_d_result_entry_settles_bets(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->normalUser()->create();
        $bet = $this->makeOpenBet($user, 7);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/two-d-results', [
                'stock_date' => '2026-06-01',
                'open_time' => '12:01',
                'twod' => '07',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.settlement_summary.won', 1)
            ->assertJsonPath('data.two_d_result.history_id', '2d-manual-2026-06-01-1201');

        // Manual entry settles but does not pay; the winner awaits admin approval.
        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'bet_result_status' => BetResultStatus::WON->value,
            'payout_status' => BetPayoutStatus::PENDING->value,
        ]);
    }

    public function test_manual_entry_accepts_double_zero(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->normalUser()->create();
        $bet = $this->makeOpenBet($user, 0);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/two-d-results', [
                'stock_date' => '2026-06-01',
                'open_time' => '12:01',
                'twod' => '00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.settlement_summary.won', 1);

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'bet_result_status' => BetResultStatus::WON->value,
        ]);
    }

    public function test_manual_entry_validation(): void
    {
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/two-d-results', [
                'stock_date' => now()->addDay()->toDateString(),
                'open_time' => '12:01',
                'twod' => '07',
            ])
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/two-d-results', [
                'stock_date' => '2026-06-01',
                'open_time' => '11:00',
                'twod' => '07',
            ])
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/two-d-results', [
                'stock_date' => '2026-06-01',
                'open_time' => '12:01',
                'twod' => '7',
            ])
            ->assertStatus(422);
    }

    public function test_update_without_confirm_returns_409_requires_revert(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->normalUser()->create();
        $this->makeOpenBet($user, 12);

        $result = TwoDResult::query()->create([
            'history_id' => 'history-api-update',
            'stock_date' => '2026-06-01',
            'stock_datetime' => '2026-06-01 12:01:00',
            'open_time' => '12:01:00',
            'twod' => '12',
            'payload' => [],
        ]);
        app(BetSettlementService::class)->settleTwoDResult($result);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/admin/two-d-results/{$result->id}", ['twod' => '34'])
            ->assertStatus(409)
            ->assertJsonPath('data.requires_revert', true)
            ->assertJsonPath('data.history_id', 'history-api-update');

        // Nothing changed.
        $this->assertDatabaseHas('two_d_results', ['id' => $result->id, 'twod' => '12']);
    }

    public function test_update_with_confirm_reverts_and_resettles(): void
    {
        $token = $this->adminToken();
        $wrongWinner = User::factory()->normalUser()->create();
        $rightWinner = User::factory()->normalUser()->create();

        $wrongBet = $this->makeOpenBet($wrongWinner, 12);
        $rightBet = $this->makeOpenBet($rightWinner, 34);

        $result = TwoDResult::query()->create([
            'history_id' => 'history-api-correct',
            'stock_date' => '2026-06-01',
            'stock_datetime' => '2026-06-01 12:01:00',
            'open_time' => '12:01:00',
            'twod' => '12',
            'payload' => [],
        ]);
        app(BetSettlementService::class)->settleTwoDResult($result);
        // Wrong winner was approved/paid before the error was caught.
        $this->approvePayout($wrongBet);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/admin/two-d-results/{$result->id}", [
                'twod' => '34',
                'confirm_revert' => true,
                'reason' => 'API returned the wrong number',
            ])
            ->assertOk()
            ->assertJsonPath('data.settlement_summary.won', 1)
            ->assertJsonPath('data.reversal.status', 'COMPLETED');

        // Correction re-settles the right winner to WON+PENDING; admin approves it.
        $this->approvePayout($rightBet);

        $this->assertDatabaseHas('bets', [
            'id' => $rightBet->id,
            'bet_result_status' => BetResultStatus::WON->value,
            'payout_status' => BetPayoutStatus::PAID_OUT->value,
        ]);
        $this->assertDatabaseHas('bets', [
            'id' => $wrongBet->id,
            'bet_result_status' => BetResultStatus::LOST->value,
        ]);

        // Wrong winner: +85k then -85k → back to 100k. Right winner: 100k + 85k.
        $this->assertSame(100_000, (int) Wallet::query()->where('user_id', $wrongWinner->id)->value('balance'));
        $this->assertSame(185_000, (int) Wallet::query()->where('user_id', $rightWinner->id)->value('balance'));
    }

    public function test_three_d_update_after_completed_run_reverts_instead_of_silent_noop(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->normalUser()->create();

        Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100_000,
            'currency' => Currency::MMK,
            'currency_locked_at' => now(),
        ]);

        $bet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::THREE_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::OPEN,
            'target_opentime' => '15:00:00',
            'stock_date' => '2026-06-10',
        ]);
        $bet->betNumbers()->create([
            'number' => 123,
            'amount' => 1_000,
            'odd' => 500,
            'potential_winning' => 500_000,
        ]);

        $result = ThreeDResult::query()->create([
            'stock_date' => '2026-06-11',
            'threed' => '999',
        ]);
        app(BetSettlementService::class)->settleThreeDResult($result);

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'bet_result_status' => BetResultStatus::LOST->value,
        ]);

        // Without confirm → 409, not a silent noop.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/admin/three-d-results/{$result->id}", ['threed' => '123'])
            ->assertStatus(409)
            ->assertJsonPath('data.requires_revert', true);

        // With confirm → reverted and re-settled: the bet now wins and is paid.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/admin/three-d-results/{$result->id}", [
                'threed' => '123',
                'confirm_revert' => true,
                'reason' => 'Typo in 3D number',
            ])
            ->assertOk()
            ->assertJsonPath('data.settlement_summary.won', 1);

        // Re-settle leaves the bet WON+PENDING; admin approves the payout.
        $this->approvePayout($bet);

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'bet_result_status' => BetResultStatus::WON->value,
            'payout_status' => BetPayoutStatus::PAID_OUT->value,
        ]);
    }

    public function test_revert_preview_and_revert_endpoints(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->normalUser()->create();
        $bet = $this->makeOpenBet($user, 12);

        $result = TwoDResult::query()->create([
            'history_id' => 'history-api-revert',
            'stock_date' => '2026-06-01',
            'stock_datetime' => '2026-06-01 12:01:00',
            'open_time' => '12:01:00',
            'twod' => '12',
            'payload' => [],
        ]);
        app(BetSettlementService::class)->settleTwoDResult($result);
        // Approve the payout so there is a real credit to preview/claw back.
        $this->approvePayout($bet);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/settlement-runs/history-api-revert/revert-preview')
            ->assertOk()
            ->assertJsonPath('data.preview.run.history_id', 'history-api-revert')
            ->assertJsonPath('data.preview.run.result_exists', true)
            ->assertJsonPath('data.preview.totals.bets', 1)
            ->assertJsonPath('data.preview.totals.paid_total', 85_000)
            ->assertJsonPath('data.preview.totals.projected_shortfall_total', 0);

        // Reason is required.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/settlement-runs/history-api-revert/revert', [])
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/settlement-runs/history-api-revert/revert', [
                'reason' => 'Wrong number from feed',
            ])
            ->assertOk()
            ->assertJsonPath('data.reversal.status', 'COMPLETED')
            ->assertJsonPath('data.reversal.total_debited', 85_000);

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'bet_result_status' => BetResultStatus::OPEN->value,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'reference_id' => $bet->id,
            'type' => WalletTransactionType::BET_WIN_REVERSAL->value,
        ]);

        // Second revert → 404.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/settlement-runs/history-api-revert/revert', [
                'reason' => 'again',
            ])
            ->assertStatus(404);
    }

    public function test_settlement_runs_index_lists_completed_and_reverted(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->normalUser()->create();
        $this->makeOpenBet($user, 12);

        $result = TwoDResult::query()->create([
            'history_id' => 'history-api-list',
            'stock_date' => '2026-06-01',
            'stock_datetime' => '2026-06-01 12:01:00',
            'open_time' => '12:01:00',
            'twod' => '12',
            'payload' => [],
        ]);
        app(BetSettlementService::class)->settleTwoDResult($result);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/settlement-runs')
            ->assertOk()
            ->assertJsonPath('data.settlement_runs.0.history_id', 'history-api-list')
            ->assertJsonPath('data.settlement_runs.0.status', 'completed');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/settlement-runs/history-api-list/revert', [
                'reason' => 'test',
            ])
            ->assertOk();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/settlement-runs')
            ->assertOk();

        $statuses = array_column($response->json('data.settlement_runs'), 'status');
        $this->assertContains('reverted', $statuses);
        $this->assertNotContains('completed', $statuses);
    }

    public function test_recovery_endpoints_require_admin(): void
    {
        $user = User::factory()->normalUser()->create();
        $userToken = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$userToken)
            ->postJson('/api/v1/admin/two-d-results', [
                'stock_date' => '2026-06-01',
                'open_time' => '12:01',
                'twod' => '07',
            ])
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$userToken)
            ->getJson('/api/v1/admin/settlement-runs')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$userToken)
            ->postJson('/api/v1/admin/settlement-runs/x/revert', ['reason' => 'x'])
            ->assertForbidden();
    }
}
