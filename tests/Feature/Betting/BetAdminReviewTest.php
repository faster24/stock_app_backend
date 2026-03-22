<?php

namespace Tests\Feature\Betting;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Models\Bet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class BetAdminReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_all_bets_with_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $userA = User::factory()->normalUser()->create();
        $userB = User::factory()->normalUser()->create();

        $oldest = Bet::factory()->for($userA)->create([
            'created_at' => Carbon::now()->subMinutes(3),
        ]);
        $middle = Bet::factory()->for($userB)->create([
            'created_at' => Carbon::now()->subMinutes(2),
        ]);
        $newest = Bet::factory()->for($userA)->create([
            'created_at' => Carbon::now()->subMinute(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/bets?page=1&page_size=2')
            ->assertOk()
            ->assertJsonPath('message', 'Bets retrieved successfully.')
            ->assertJsonCount(2, 'data.bets')
            ->assertJsonPath('data.bets.0.id', $newest->id)
            ->assertJsonPath('data.bets.0.user_id', $userA->id)
            ->assertJsonPath('data.bets.1.id', $middle->id)
            ->assertJsonPath('data.bets.1.user_id', $userB->id)
            ->assertJsonPath('errors', null);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/bets?page=2&page_size=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.bets')
            ->assertJsonPath('data.bets.0.id', $oldest->id);
    }

    public function test_non_admin_cannot_list_or_reject_bets(): void
    {
        $bet = Bet::factory()->create();
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/bets')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/admin/bets/'.$bet->id.'/status', [
                'status' => BetStatus::REJECTED->value,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');
    }

    public function test_admin_can_reject_pending_bet(): void
    {
        $bet = Bet::factory()->create([
            'status' => BetStatus::PENDING,
        ]);
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/admin/bets/'.$bet->id.'/status', [
                'status' => BetStatus::REJECTED->value,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Bet status updated successfully.')
            ->assertJsonPath('data.bet.id', $bet->id)
            ->assertJsonPath('data.bet.status', BetStatus::REJECTED->value)
            ->assertJsonPath('data.bet.bet_result_status', BetResultStatus::INVALID->value)
            ->assertJsonPath('errors', null);

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'status' => BetStatus::REJECTED->value,
            'bet_result_status' => BetResultStatus::INVALID->value,
        ]);
    }

    public function test_admin_reject_returns_not_found_for_missing_bet(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;
        $missingBetId = (string) Str::uuid();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/admin/bets/'.$missingBetId.'/status', [
                'status' => BetStatus::REJECTED->value,
            ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Bet not found.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.bet.0', 'The selected bet is invalid.');
    }

    public function test_admin_can_accept_pending_bet(): void
    {
        $bet = Bet::factory()->create([
            'status' => BetStatus::PENDING,
        ]);
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/admin/bets/'.$bet->id.'/status', [
                'status' => BetStatus::ACCEPTED->value,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Bet status updated successfully.')
            ->assertJsonPath('data.bet.id', $bet->id)
            ->assertJsonPath('data.bet.status', BetStatus::ACCEPTED->value)
            ->assertJsonPath('errors', null);

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'status' => BetStatus::ACCEPTED->value,
        ]);
    }

    public function test_admin_reject_returns_conflict_for_illegal_transition(): void
    {
        $bet = Bet::factory()->create([
            'status' => BetStatus::ACCEPTED,
        ]);
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/admin/bets/'.$bet->id.'/status', [
                'status' => BetStatus::REJECTED->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Illegal review status transition.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.status.0', 'Illegal review status transition.');
    }

    public function test_admin_can_refund_bets_from_non_paid_out_states(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $pendingBet = Bet::factory()->create([
            'status' => BetStatus::PENDING,
            'payout_status' => BetPayoutStatus::PENDING,
        ]);
        $acceptedBet = Bet::factory()->create([
            'status' => BetStatus::ACCEPTED,
            'payout_status' => BetPayoutStatus::PENDING,
        ]);
        $rejectedBet = Bet::factory()->create([
            'status' => BetStatus::REJECTED,
            'payout_status' => BetPayoutStatus::PENDING,
        ]);

        foreach ([$pendingBet, $acceptedBet, $rejectedBet] as $bet) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->patchJson('/api/v1/admin/bets/'.$bet->id.'/status', [
                    'status' => BetStatus::REFUNDED->value,
                ])
                ->assertOk()
                ->assertJsonPath('message', 'Bet status updated successfully.')
                ->assertJsonPath('data.bet.id', $bet->id)
                ->assertJsonPath('data.bet.status', BetStatus::REFUNDED->value)
                ->assertJsonPath('data.bet.bet_result_status', BetResultStatus::INVALID->value)
                ->assertJsonPath('data.bet.payout_status', BetPayoutStatus::REFUNDED->value)
                ->assertJsonPath('errors', null);
        }

        $this->assertDatabaseHas('bets', [
            'id' => $pendingBet->id,
            'status' => BetStatus::REFUNDED->value,
            'bet_result_status' => BetResultStatus::INVALID->value,
            'payout_status' => BetPayoutStatus::REFUNDED->value,
        ]);
        $this->assertDatabaseHas('bets', [
            'id' => $acceptedBet->id,
            'status' => BetStatus::REFUNDED->value,
            'bet_result_status' => BetResultStatus::INVALID->value,
            'payout_status' => BetPayoutStatus::REFUNDED->value,
        ]);
        $this->assertDatabaseHas('bets', [
            'id' => $rejectedBet->id,
            'status' => BetStatus::REFUNDED->value,
            'bet_result_status' => BetResultStatus::INVALID->value,
            'payout_status' => BetPayoutStatus::REFUNDED->value,
        ]);
    }

    public function test_admin_refund_returns_conflict_when_bet_is_paid_out(): void
    {
        $bet = Bet::factory()->create([
            'status' => BetStatus::ACCEPTED,
            'payout_status' => BetPayoutStatus::PAID_OUT,
        ]);
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/admin/bets/'.$bet->id.'/status', [
                'status' => BetStatus::REFUNDED->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Paid out bets cannot be refunded.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.status.0', 'Paid out bets cannot be refunded.');

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'status' => BetStatus::ACCEPTED->value,
            'payout_status' => BetPayoutStatus::PAID_OUT->value,
        ]);
    }

    public function test_admin_refund_returns_conflict_when_bet_is_already_refunded(): void
    {
        $bet = Bet::factory()->create([
            'status' => BetStatus::PENDING,
            'payout_status' => BetPayoutStatus::REFUNDED,
        ]);
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/admin/bets/'.$bet->id.'/status', [
                'status' => BetStatus::REFUNDED->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Paid out bets cannot be refunded.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.status.0', 'Paid out bets cannot be refunded.');
    }

    public function test_admin_accept_returns_conflict_for_rejected_bet(): void
    {
        $bet = Bet::factory()->create([
            'status' => BetStatus::REJECTED,
            'payout_status' => BetPayoutStatus::PENDING,
        ]);
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/admin/bets/'.$bet->id.'/status', [
                'status' => BetStatus::ACCEPTED->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Illegal review status transition.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.status.0', 'Illegal review status transition.');
    }
}
