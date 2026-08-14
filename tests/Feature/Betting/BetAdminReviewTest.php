<?php

namespace Tests\Feature\Betting;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Models\Bet;
use App\Models\User;
use App\Models\Wallet;
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
            ->assertJsonPath('data.bets.0.user.id', $userA->id)
            ->assertJsonPath('data.bets.0.user.email', $userA->email)
            ->assertJsonPath('data.bets.1.id', $middle->id)
            ->assertJsonPath('data.bets.1.user_id', $userB->id)
            ->assertJsonPath('data.bets.1.user.id', $userB->id)
            ->assertJsonPath('data.bets.1.user.email', $userB->email)
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
        $owner = User::factory()->normalUser()->create();
        $this->createWalletForUser($owner, 5_000);
        $bet   = Bet::factory()->for($owner)->create([
            'status'       => BetStatus::PENDING,
            'total_amount' => '1000.00',
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

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'   => $owner->id,
            'type'      => 'BET_REFUND',
            'direction' => 'CREDIT',
            'amount'    => 1000,
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

        $ownerA = User::factory()->normalUser()->create();
        $this->createWalletForUser($ownerA, 0);
        $pendingBet = Bet::factory()->for($ownerA)->create([
            'status'        => BetStatus::PENDING,
            'payout_status' => BetPayoutStatus::PENDING,
            'total_amount'  => '1000.00',
        ]);

        $ownerB = User::factory()->normalUser()->create();
        $this->createWalletForUser($ownerB, 0);
        $acceptedBet = Bet::factory()->for($ownerB)->create([
            'status'        => BetStatus::ACCEPTED,
            'payout_status' => BetPayoutStatus::PENDING,
            'total_amount'  => '1000.00',
        ]);

        $ownerC = User::factory()->normalUser()->create();
        $this->createWalletForUser($ownerC, 0);
        $rejectedBet = Bet::factory()->for($ownerC)->create([
            'status'        => BetStatus::REJECTED,
            'payout_status' => BetPayoutStatus::PENDING,
            'total_amount'  => '1000.00',
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

    public function test_admin_bet_list_returns_pagination_meta(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        Bet::factory()->count(3)->create();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/bets?page=1&page_size=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.bets')
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 3);
    }

    public function test_admin_can_filter_bets_by_stock_date_range(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $before = Bet::factory()->create(['stock_date' => '2026-05-01']);
        $inside = Bet::factory()->create(['stock_date' => '2026-05-10']);
        $after = Bet::factory()->create(['stock_date' => '2026-05-20']);

        $ids = collect(
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson('/api/v1/admin/bets?stock_date_from=2026-05-05&stock_date_to=2026-05-15')
                ->assertOk()
                ->json('data.bets')
        )->pluck('id')->all();

        $this->assertSame([$inside->id], $ids);
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($after->id, $ids);
    }

    public function test_admin_can_search_bets_by_username_and_bet_id_prefix(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $target = User::factory()->normalUser()->create(['username' => 'zarmani_win']);
        $other = User::factory()->normalUser()->create(['username' => 'someone_else']);

        $targetBet = Bet::factory()->for($target)->create();
        $otherBet = Bet::factory()->for($other)->create();

        $byUsername = collect(
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson('/api/v1/admin/bets?search=zarmani')
                ->assertOk()
                ->json('data.bets')
        )->pluck('id')->all();

        $this->assertSame([$targetBet->id], $byUsername);

        // Ordered UUIDs share a long leading prefix, so only a full/near-full id
        // is guaranteed unique — that is the copy-the-id-off-the-list path.
        $byIdPrefix = collect(
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson('/api/v1/admin/bets?search='.$otherBet->id)
                ->assertOk()
                ->json('data.bets')
        )->pluck('id')->all();

        $this->assertSame([$otherBet->id], $byIdPrefix);
    }

    public function test_admin_bet_search_still_matches_bets_of_soft_deleted_users(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $ghost = User::factory()->normalUser()->create(['username' => 'ghost_punter']);
        $ghostBet = Bet::factory()->for($ghost)->create();
        $ghost->delete();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/bets?search=ghost_punter')
            ->assertOk()
            ->assertJsonCount(1, 'data.bets')
            ->assertJsonPath('data.bets.0.id', $ghostBet->id);
    }

    public function test_admin_bet_list_rejects_invalid_filter_values(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/bets?bet_result_status=NOPE')
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/bets?stock_date_from=2026-05-10&stock_date_to=2026-05-01')
            ->assertStatus(422);
    }

    private function createWalletForUser(User $user, int $balance = 0): Wallet
    {
        return Wallet::factory()->create([
            'user_id'            => $user->id,
            'balance'            => $balance,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
    }
}
