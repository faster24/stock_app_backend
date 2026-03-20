<?php

namespace Tests\Feature\Betting;

use App\Enums\BetType;
use App\Models\Bet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class BetApiWriteAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_store_update_and_delete_own_bet(): void
    {
        $owner = User::factory()->normalUser()->create();
        $token = $owner->createToken('auth_token')->plainTextToken;

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/bets', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => BetType::TWO_D->value,
                'target_opentime' => '11:00:00',
                'amount' => 1500,
                'bet_numbers' => [11, 22],
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('message', 'Bet created successfully.')
            ->assertJsonPath('data.bet.user_id', $owner->id)
            ->assertJsonPath('data.bet.target_opentime', '11:00:00')
            ->assertJsonPath('data.bet.stock_date', Carbon::now()->startOfDay()->utc()->format('Y-m-d\TH:i:s.000000\Z'))
            ->assertJsonPath('data.bet.bet_numbers.0.number', 11)
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'message',
                'data' => ['bet'],
                'errors',
            ]);

        $betId = (string) $createResponse->json('data.bet.id');
        $betSlip = (string) $createResponse->json('data.bet.bet_slip');

        $this->assertTrue(Str::isUuid($betId));
        $this->assertTrue(Str::isUuid($betSlip));

        $this->assertDatabaseHas('bets', [
            'id' => $betId,
            'user_id' => $owner->id,
            'bet_slip' => $betSlip,
            'bet_type' => BetType::TWO_D->value,
            'target_opentime' => '11:00:00',
            'stock_date' => Carbon::now()->toDateString(),
            'amount' => 1500,
            'total_amount' => 3000.00,
        ]);

        $updateResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/bets/'.$betId, [
                'bet_type' => BetType::THREE_D->value,
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
            'bet_type' => BetType::THREE_D->value,
            'amount' => 2500,
            'total_amount' => 5000.00,
        ]);

        $this->assertDatabaseHas('bet_numbers', [
            'bet_id' => $betId,
            'number' => 33,
        ]);

        $this->assertDatabaseMissing('bet_numbers', [
            'bet_id' => $betId,
            'number' => 11,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/bets/'.$betId, [
                'amount' => 1000,
            ])
            ->assertOk();

        $this->assertDatabaseHas('bets', [
            'id' => $betId,
            'amount' => 1000,
            'total_amount' => 2000.00,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/bets/'.$betId, [
                'bet_numbers' => [123, 234, 255],
            ])
            ->assertOk();

        $this->assertDatabaseHas('bets', [
            'id' => $betId,
            'amount' => 1000,
            'total_amount' => 3000.00,
        ]);

        $this->assertDatabaseHas('bet_numbers', [
            'bet_id' => $betId,
            'number' => 123,
        ]);

        $this->assertDatabaseMissing('bet_numbers', [
            'bet_id' => $betId,
            'number' => 33,
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

    public function test_owner_cannot_submit_invalid_amount_when_updating_bet(): void
    {
        $owner = User::factory()->normalUser()->create();
        $bet = Bet::factory()->for($owner)->create();
        $token = $owner->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/bets/'.$bet->id, [
                'amount' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['amount'],
            ]);

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'amount' => $bet->amount,
        ]);
    }
}
