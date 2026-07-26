<?php

namespace Tests\Feature\Betting;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Models\Bet;
use App\Models\TwoDResult;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BetPayoutApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    private function seedResult(): void
    {
        TwoDResult::query()->create([
            'history_id' => 'hist-payout',
            'stock_date' => '2026-03-19',
            'stock_datetime' => '2026-03-19 11:00:00',
            'open_time' => '11:00:00',
            'twod' => '12',
            'payload' => [],
        ]);
    }

    private function winningBet(BetPayoutStatus $payout = BetPayoutStatus::PENDING, BetResultStatus $result = BetResultStatus::WON): Bet
    {
        $user = User::factory()->normalUser()->create();
        Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 50_000,
            'currency' => Currency::MMK,
            'currency_locked_at' => now(),
        ]);

        $bet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => $result,
            'payout_status' => $payout,
            'target_opentime' => '11:00:00',
            'stock_date' => '2026-03-19',
            'settled_result_history_id' => 'hist-payout',
        ]);
        $bet->betNumbers()->create(['number' => 12, 'amount' => 1000, 'potential_winning' => 85_000]);

        return $bet;
    }

    public function test_admin_can_approve_a_winning_bet_payout(): void
    {
        $this->seedResult();
        $bet = $this->winningBet();
        $admin = User::factory()->admin()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->token($admin))
            ->postJson("/api/v1/admin/bets/{$bet->id}/payout", ['payout_note' => 'ok'])
            ->assertOk()
            ->assertJsonPath('data.bet.payout_status', BetPayoutStatus::PAID_OUT->value);

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'payout_status' => BetPayoutStatus::PAID_OUT->value,
            'paid_out_by_user_id' => $admin->id,
        ]);
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->seedResult();
        $bet = $this->winningBet();

        $this->postJson("/api/v1/admin/bets/{$bet->id}/payout")->assertStatus(401);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->seedResult();
        $bet = $this->winningBet();
        $user = User::factory()->normalUser()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/admin/bets/{$bet->id}/payout")
            ->assertStatus(403);
    }

    public function test_approving_a_non_won_bet_returns_409(): void
    {
        $bet = $this->winningBet(BetPayoutStatus::PENDING, BetResultStatus::LOST);
        $admin = User::factory()->admin()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->token($admin))
            ->postJson("/api/v1/admin/bets/{$bet->id}/payout")
            ->assertStatus(409);

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'payout_status' => BetPayoutStatus::PENDING->value,
        ]);
    }

    public function test_bulk_approval_pays_a_whole_draw(): void
    {
        $this->seedResult();
        $b1 = $this->winningBet();
        $b2 = $this->winningBet();
        $admin = User::factory()->admin()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->token($admin))
            ->postJson('/api/v1/admin/bets/payout/bulk', [
                'stock_date' => '2026-03-19',
                'target_opentime' => '11:00:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.summary.approved', 2);

        $this->assertDatabaseHas('bets', ['id' => $b1->id, 'payout_status' => BetPayoutStatus::PAID_OUT->value]);
        $this->assertDatabaseHas('bets', ['id' => $b2->id, 'payout_status' => BetPayoutStatus::PAID_OUT->value]);
    }

    public function test_admin_can_filter_bets_to_the_payout_queue(): void
    {
        $this->seedResult();
        $pendingWinner = $this->winningBet(BetPayoutStatus::PENDING, BetResultStatus::WON);
        $this->winningBet(BetPayoutStatus::PAID_OUT, BetResultStatus::WON); // excluded
        $admin = User::factory()->admin()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($admin))
            ->getJson('/api/v1/admin/bets?bet_result_status=WON&payout_status=PENDING')
            ->assertOk();

        $ids = collect($response->json('data.bets'))->pluck('id')->all();
        $this->assertContains($pendingWinner->id, $ids);
        $this->assertCount(1, $ids);
    }
}
