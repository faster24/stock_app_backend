<?php

namespace Tests\Feature\Betting;

use App\Enums\BetType;
use App\Models\Bet;
use App\Models\Round;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BetApiWriteAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_store_update_and_delete_own_bet(): void
    {
        $owner = User::factory()->normalUser()->create();
        $round = Round::factory()->create();
        $token = $owner->createToken('auth_token')->plainTextToken;

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', [
                'round_id' => $round->id,
                'bet_type' => BetType::STRAIGHT->value,
                'amount' => 1500,
                'bet_numbers' => [11, 22],
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('message', 'Bet created successfully.')
            ->assertJsonPath('data.bet.user_id', $owner->id)
            ->assertJsonPath('data.bet.round_id', $round->id)
            ->assertJsonPath('data.bet.bet_numbers.0.number', 11)
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'message',
                'data' => ['bet'],
                'errors',
            ]);

        $betId = (int) $createResponse->json('data.bet.id');

        $this->assertDatabaseHas('bets', [
            'id' => $betId,
            'user_id' => $owner->id,
            'round_id' => $round->id,
            'bet_type' => BetType::STRAIGHT->value,
            'amount' => 1500,
        ]);

        $updateResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/bets/'.$betId, [
                'bet_type' => BetType::PERMUTATION->value,
                'amount' => 2500,
                'bet_numbers' => [33, 44],
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('message', 'Bet updated successfully.')
            ->assertJsonPath('data.bet.id', $betId)
            ->assertJsonPath('data.bet.user_id', $owner->id)
            ->assertJsonPath('data.bet.bet_numbers.0.number', 33)
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'message',
                'data' => ['bet'],
                'errors',
            ]);

        $this->assertDatabaseHas('bets', [
            'id' => $betId,
            'bet_type' => BetType::PERMUTATION->value,
            'amount' => 2500,
        ]);

        $this->assertDatabaseHas('bet_numbers', [
            'bet_id' => $betId,
            'number' => 33,
        ]);

        $this->assertDatabaseMissing('bet_numbers', [
            'bet_id' => $betId,
            'number' => 11,
        ]);

        $deleteResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/bets/'.$betId);

        $deleteResponse->assertOk()
            ->assertJsonPath('message', 'Bet deleted successfully.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors',
            ]);

        $this->assertDatabaseMissing('bets', [
            'id' => $betId,
        ]);
    }

    public function test_non_owner_gets_404_when_updating_or_deleting_another_users_bet(): void
    {
        $owner = User::factory()->normalUser()->create();
        $nonOwner = User::factory()->normalUser()->create();
        $ownersBet = Bet::factory()->for($owner)->create();
        $token = $nonOwner->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/bets/'.$ownersBet->id, [
                'amount' => 3000,
            ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Bet not found.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.bet.0', 'The selected bet is invalid.')
            ->assertJsonStructure([
                'message',
                'data',
                'errors',
            ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/bets/'.$ownersBet->id)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Bet not found.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.bet.0', 'The selected bet is invalid.')
            ->assertJsonStructure([
                'message',
                'data',
                'errors',
            ]);
    }

    public function test_owner_cannot_update_round_id_after_creation(): void
    {
        $owner = User::factory()->normalUser()->create();
        $currentRound = Round::factory()->create();
        $newRound = Round::factory()->create();
        $bet = Bet::factory()->for($owner)->for($currentRound)->create();
        $token = $owner->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/bets/'.$bet->id, [
                'round_id' => $newRound->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['round_id'],
            ]);

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'round_id' => $currentRound->id,
        ]);
    }
}
