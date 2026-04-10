<?php

namespace Tests\Feature\Betting;

use App\Enums\BetStatus;
use App\Models\Bet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BetAcceptedPaymentsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_accepted_payment_transitions(): void
    {
        $this->getJson('/api/v1/bets/accepted-payments')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');
    }

    public function test_owner_can_list_only_accepted_payment_transitions_ordered_by_updated_at_desc(): void
    {
        $owner = User::factory()->normalUser()->create();
        $otherUser = User::factory()->normalUser()->create();
        $token = $owner->createToken('auth_token')->plainTextToken;

        $excludedPending = Bet::factory()->for($owner)->create([
            'status' => BetStatus::PENDING,
            'updated_at' => Carbon::parse('2026-03-25 11:00:00'),
        ]);
        $excludedRejected = Bet::factory()->for($owner)->create([
            'status' => BetStatus::REJECTED,
            'updated_at' => Carbon::parse('2026-03-25 12:00:00'),
        ]);

        $oldAccepted = Bet::factory()->for($owner)->create([
            'status' => BetStatus::ACCEPTED,
            'updated_at' => Carbon::parse('2026-03-25 13:00:00'),
        ]);
        $newAccepted = Bet::factory()->for($owner)->create([
            'status' => BetStatus::ACCEPTED,
            'updated_at' => Carbon::parse('2026-03-25 14:00:00'),
        ]);

        $excludedOtherUser = Bet::factory()->for($otherUser)->create([
            'status' => BetStatus::ACCEPTED,
            'updated_at' => Carbon::parse('2026-03-25 15:00:00'),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/bets/accepted-payments')
            ->assertOk()
            ->assertJsonPath('message', 'Accepted payment transitions retrieved successfully.')
            ->assertJsonCount(2, 'data.accepted_payments')
            ->assertJsonPath('data.accepted_payments.0.id', $newAccepted->id)
            ->assertJsonPath('data.accepted_payments.1.id', $oldAccepted->id)
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'accepted_payments' => [[
                        'id',
                        'transaction_id_last_two_digits',
                        'total_amount',
                        'status',
                        'bet_result_status',
                        'payout_status',
                        'pay_slip',
                        'payout_proof',
                        'updated_at',
                    ]],
                ],
                'errors',
            ]);

        $returnedIds = collect($response->json('data.accepted_payments'))->pluck('id')->all();
        $this->assertNotContains($excludedPending->id, $returnedIds);
        $this->assertNotContains($excludedRejected->id, $returnedIds);
        $this->assertNotContains($excludedOtherUser->id, $returnedIds);
    }

    public function test_accepted_payment_transitions_support_pagination(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $oldest = Bet::factory()->for($user)->create([
            'status' => BetStatus::ACCEPTED,
            'updated_at' => Carbon::parse('2026-03-25 11:00:00'),
        ]);
        $middle = Bet::factory()->for($user)->create([
            'status' => BetStatus::ACCEPTED,
            'updated_at' => Carbon::parse('2026-03-25 12:00:00'),
        ]);
        $newest = Bet::factory()->for($user)->create([
            'status' => BetStatus::ACCEPTED,
            'updated_at' => Carbon::parse('2026-03-25 13:00:00'),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/bets/accepted-payments?page=1&page_size=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.accepted_payments')
            ->assertJsonPath('data.accepted_payments.0.id', $newest->id)
            ->assertJsonPath('data.accepted_payments.1.id', $middle->id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/bets/accepted-payments?page=2&page_size=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.accepted_payments')
            ->assertJsonPath('data.accepted_payments.0.id', $oldest->id);
    }
}
