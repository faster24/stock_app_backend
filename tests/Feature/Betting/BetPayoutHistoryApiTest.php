<?php

namespace Tests\Feature\Betting;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Models\Bet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BetPayoutHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_payout_history(): void
    {
        $this->getJson('/api/v1/bets/payout-history')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');
    }

    public function test_owner_can_list_accepted_won_paid_out_or_refunded_bets_with_timeline_ordering(): void
    {
        $owner = User::factory()->normalUser()->create();
        $otherUser = User::factory()->normalUser()->create();
        $token = $owner->createToken('auth_token')->plainTextToken;

        $excludedPending = Bet::factory()->for($owner)->create([
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => BetPayoutStatus::PENDING,
            'paid_out_at' => null,
            'updated_at' => Carbon::parse('2026-03-25 10:00:00'),
        ]);

        $paidOutWithNewerPaidOutAt = Bet::factory()->for($owner)->create([
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => BetPayoutStatus::PAID_OUT,
            'paid_out_at' => Carbon::parse('2026-03-25 14:00:00'),
            'updated_at' => Carbon::parse('2026-03-25 14:05:00'),
        ]);

        $includedRefunded = Bet::factory()->for($owner)->create([
            'status' => BetStatus::REJECTED,
            'bet_result_status' => BetResultStatus::LOST,
            'payout_status' => BetPayoutStatus::REFUNDED,
            'paid_out_at' => Carbon::parse('2026-03-25 13:00:00'),
            'updated_at' => Carbon::parse('2026-03-25 13:01:00'),
        ]);

        $paidOutWithNewerUpdate = Bet::factory()->for($owner)->create([
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => BetPayoutStatus::PAID_OUT,
            'paid_out_at' => Carbon::parse('2026-03-25 13:00:00'),
            'updated_at' => Carbon::parse('2026-03-25 13:03:00'),
        ]);

        $excludedAcceptedButLost = Bet::factory()->for($owner)->create([
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::LOST,
            'payout_status' => BetPayoutStatus::PAID_OUT,
            'paid_out_at' => Carbon::parse('2026-03-25 12:00:00'),
            'updated_at' => Carbon::parse('2026-03-25 12:02:00'),
        ]);

        $excludedRejectedButPaidOut = Bet::factory()->for($owner)->create([
            'status' => BetStatus::REJECTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => BetPayoutStatus::PAID_OUT,
            'paid_out_at' => Carbon::parse('2026-03-25 11:00:00'),
            'updated_at' => Carbon::parse('2026-03-25 11:02:00'),
        ]);

        $excludedOtherUser = Bet::factory()->for($otherUser)->create([
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => BetPayoutStatus::PAID_OUT,
            'paid_out_at' => Carbon::parse('2026-03-25 15:00:00'),
            'updated_at' => Carbon::parse('2026-03-25 15:01:00'),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/bets/payout-history')
            ->assertOk()
            ->assertJsonPath('message', 'Payout history retrieved successfully.')
            ->assertJsonCount(3, 'data.payout_history')
            ->assertJsonPath('data.payout_history.0.id', $paidOutWithNewerPaidOutAt->id)
            ->assertJsonPath('data.payout_history.1.id', $paidOutWithNewerUpdate->id)
            ->assertJsonPath('data.payout_history.2.id', $includedRefunded->id)
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'payout_history' => [[
                        'id',
                        'total_amount',
                        'status',
                        'payout_status',
                        'paid_out_at',
                        'payout_reference',
                        'payout_note',
                        'pay_slip',
                        'payout_proof',
                    ]],
                ],
                'errors',
            ]);

        $returnedIds = collect($response->json('data.payout_history'))->pluck('id')->all();
        $this->assertNotContains($excludedPending->id, $returnedIds);
        $this->assertNotContains($excludedAcceptedButLost->id, $returnedIds);
        $this->assertNotContains($excludedRejectedButPaidOut->id, $returnedIds);
        $this->assertNotContains($excludedOtherUser->id, $returnedIds);
    }

    public function test_payout_history_supports_pagination(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $oldest = Bet::factory()->for($user)->create([
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => BetPayoutStatus::PAID_OUT,
            'paid_out_at' => Carbon::parse('2026-03-25 11:00:00'),
            'updated_at' => Carbon::parse('2026-03-25 11:01:00'),
        ]);
        $middle = Bet::factory()->for($user)->create([
            'status' => BetStatus::PENDING,
            'bet_result_status' => BetResultStatus::OPEN,
            'payout_status' => BetPayoutStatus::REFUNDED,
            'paid_out_at' => Carbon::parse('2026-03-25 12:00:00'),
            'updated_at' => Carbon::parse('2026-03-25 12:01:00'),
        ]);
        $newest = Bet::factory()->for($user)->create([
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => BetPayoutStatus::PAID_OUT,
            'paid_out_at' => Carbon::parse('2026-03-25 13:00:00'),
            'updated_at' => Carbon::parse('2026-03-25 13:01:00'),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/bets/payout-history?page=1&page_size=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.payout_history')
            ->assertJsonPath('data.payout_history.0.id', $newest->id)
            ->assertJsonPath('data.payout_history.1.id', $middle->id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/bets/payout-history?page=2&page_size=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.payout_history')
            ->assertJsonPath('data.payout_history.0.id', $oldest->id);
    }
}
