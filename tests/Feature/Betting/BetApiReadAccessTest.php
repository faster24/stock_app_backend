<?php

namespace Tests\Feature\Betting;

use App\Enums\BankName;
use App\Models\Bet;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BetApiReadAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_bets(): void
    {
        $this->getJson('/api/v1/bets')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.')
            ->assertJsonStructure([
                'message',
                'data',
                'errors',
            ]);
    }

    public function test_owner_can_list_and_show_own_bets(): void
    {
        $owner = User::factory()->normalUser()->create();
        $otherUser = User::factory()->normalUser()->create();
        Wallet::query()->create([
            'user_id' => $owner->id,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'Owner Name',
            'account_number' => '99887766',
        ]);
        $ownerBet = Bet::factory()->for($owner)->create();
        Bet::factory()->for($otherUser)->create();
        $token = $owner->createToken('auth_token')->plainTextToken;

        $this->assertTrue(Str::isUuid($ownerBet->id));
        $this->assertTrue(Str::isUuid($ownerBet->bet_slip));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/bets')
            ->assertOk()
            ->assertJsonPath('message', 'Bets retrieved successfully.')
            ->assertJsonPath('data.bets.0.id', $ownerBet->id)
            ->assertJsonPath('data.bets.0.user_id', $owner->id)
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'message',
                'data' => ['bets'],
                'errors',
            ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/bets/'.$ownerBet->id)
            ->assertOk()
            ->assertJsonPath('message', 'Bet retrieved successfully.')
            ->assertJsonPath('data.bet.id', $ownerBet->id)
            ->assertJsonPath('data.bet.user_id', $owner->id)
            ->assertJsonPath('data.bet.user.id', $owner->id)
            ->assertJsonPath('data.bet.user.email', $owner->email)
            ->assertJsonPath('data.bet.user.wallet.bank_name', BankName::KBZ->value)
            ->assertJsonPath('data.bet.user.wallet.account_name', 'Owner Name')
            ->assertJsonPath('data.bet.user.wallet.account_number', '99887766')
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'message',
                'data' => ['bet'],
                'errors',
            ]);
    }

    public function test_non_owner_gets_404_when_showing_another_users_bet(): void
    {
        $owner = User::factory()->normalUser()->create();
        $nonOwner = User::factory()->normalUser()->create();
        $ownersBet = Bet::factory()->for($owner)->create();
        $token = $nonOwner->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/bets/'.$ownersBet->id)
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
