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

    public function test_owner_can_store_and_delete_own_bet(): void
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

    public function test_updating_bet_is_not_allowed_for_owner_non_owner_or_admin(): void
    {
        $owner = User::factory()->normalUser()->create();
        $otherUser = User::factory()->normalUser()->create();
        $admin = User::factory()->admin()->create();
        $bet = Bet::factory()->for($owner)->create();

        $ownerToken = $owner->createToken('auth_token')->plainTextToken;
        $otherUserToken = $otherUser->createToken('auth_token')->plainTextToken;
        $adminToken = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->putJson('/api/v1/bets/'.$bet->id, ['amount' => 3000])
            ->assertStatus(405);

        $this->withHeader('Authorization', 'Bearer '.$otherUserToken)
            ->putJson('/api/v1/bets/'.$bet->id, ['amount' => 3000])
            ->assertStatus(405);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->putJson('/api/v1/bets/'.$bet->id, ['amount' => 3000])
            ->assertStatus(405);
    }

    public function test_non_owner_still_gets_404_when_deleting_another_users_bet(): void
    {
        $owner = User::factory()->normalUser()->create();
        $nonOwner = User::factory()->normalUser()->create();
        $ownersBet = Bet::factory()->for($owner)->create();
        $token = $nonOwner->createToken('auth_token')->plainTextToken;

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
}
