<?php

namespace Tests\Feature\Betting;

use App\Enums\BetType;
use App\Enums\BankName;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Models\Bet;
use App\Models\OddSetting;
use App\Models\User;
use App\Models\Wallet;
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
        $this->seedOddSetting(BetType::TWO_D, Currency::MMK, OddSettingUserType::USER, '80.00');

        $owner = User::factory()->normalUser()->create();
        $this->createBankInfo($owner);
        $token = $owner->createToken('auth_token')->plainTextToken;

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/bets', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => BetType::TWO_D->value,
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'transaction_id_last_two_digits' => '45',
                'bet_numbers' => [
                    ['number' => 11, 'amount' => 1000],
                    ['number' => 22, 'amount' => 1500],
                ],
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('message', 'Bet created successfully.')
            ->assertJsonPath('data.bet.user_id', $owner->id)
            ->assertJsonPath('data.bet.target_opentime', '11:00:00')
            ->assertJsonPath('data.bet.stock_date', Carbon::now()->startOfDay()->utc()->format('Y-m-d\TH:i:s.000000\Z'))
            ->assertJsonPath('data.bet.currency', Currency::MMK->value)
            ->assertJsonPath('data.bet.bet_numbers.0.number', 11)
            ->assertJsonPath('data.bet.bet_numbers.0.amount', 1000)
            ->assertJsonPath('data.bet.bet_numbers.0.potential_winning', '80000.00')
            ->assertJsonPath('data.bet.total_amount', '2500.00')
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
            'currency' => Currency::MMK->value,
            'target_opentime' => '11:00:00',
            'transaction_id_last_two_digits' => '45',
            'stock_date' => Carbon::now()->toDateString(),
            'total_amount' => 2500.00,
        ]);
        $this->assertDatabaseHas('bet_numbers', [
            'bet_id' => $betId,
            'number' => 11,
            'amount' => 1000,
            'potential_winning' => 80000.00,
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
            ->putJson('/api/v1/bets/'.$bet->id, ['target_opentime' => '12:01:00'])
            ->assertStatus(405);

        $this->withHeader('Authorization', 'Bearer '.$otherUserToken)
            ->putJson('/api/v1/bets/'.$bet->id, ['target_opentime' => '12:01:00'])
            ->assertStatus(405);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->putJson('/api/v1/bets/'.$bet->id, ['target_opentime' => '12:01:00'])
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

    public function test_legacy_bet_numbers_integer_payload_is_rejected(): void
    {
        $this->seedOddSetting(BetType::TWO_D, Currency::MMK, OddSettingUserType::USER, '80.00');

        $owner = User::factory()->normalUser()->create();
        $this->createBankInfo($owner);
        $token = $owner->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/bets', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => BetType::TWO_D->value,
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'transaction_id_last_two_digits' => '45',
                'bet_numbers' => [11, 22],
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bet_numbers.0', 'bet_numbers.1'],
            ]);
    }

    private function seedOddSetting(BetType $betType, Currency $currency, OddSettingUserType $userType, string $odd): void
    {
        OddSetting::query()->updateOrCreate([
            'bet_type' => $betType,
            'currency' => $currency,
            'user_type' => $userType,
        ], [
            'odd' => $odd,
            'is_active' => true,
        ]);
    }

    private function createBankInfo(User $user): void
    {
        Wallet::query()->create([
            'user_id' => $user->id,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'Main User',
            'account_number' => '111222333',
        ]);
    }
}
