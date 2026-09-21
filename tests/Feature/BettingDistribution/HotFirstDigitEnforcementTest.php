<?php

namespace Tests\Feature\BettingDistribution;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Models\DigitControl;
use App\Models\NumberControl;
use App\Models\OddSetting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HotFirstDigitEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function today(): string
    {
        return Carbon::now()->toDateString();
    }

    private function seedOddSetting(BetType $betType = BetType::TWO_D): void
    {
        OddSetting::query()->updateOrCreate([
            'bet_type' => $betType,
            'currency' => Currency::MMK,
            'user_type' => OddSettingUserType::USER,
        ], [
            'odd' => $betType === BetType::TWO_D ? '80.00' : '500.00',
            'is_active' => true,
        ]);
    }

    private function makeUserWithWallet(int $balance = 100_000): array
    {
        $user = User::factory()->normalUser()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => $balance,
            'currency' => Currency::MMK,
            'currency_locked_at' => now(),
            'bank_name' => 'KBZ',
            'account_name' => 'Test User',
            'account_number' => '1234567890',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        return [$user, $wallet, $token];
    }

    private function betPayload(array $betNumbers, array $overrides = []): array
    {
        return array_merge([
            'bet_type' => '2D',
            'currency' => 'MMK',
            'target_opentime' => '16:30:00',
            'bet_numbers' => $betNumbers,
        ], $overrides);
    }

    private function markHot(int $digit, array $overrides = []): DigitControl
    {
        return DigitControl::query()->create(array_merge([
            'bet_type' => '2D',
            'currency' => 'MMK',
            'digit' => $digit,
            'target_opentime' => '16:30:00',
            'stock_date' => $this->today(),
        ], $overrides));
    }

    public function test_direct_bet_on_hot_first_digit_is_rejected_without_side_effects(): void
    {
        $this->seedOddSetting();
        [, $wallet, $token] = $this->makeUserWithWallet();
        $this->markHot(3);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([
                ['number' => 34, 'amount' => 1000, 'origin' => 'direct'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('data.code', 'BET_NUMBERS_UNAVAILABLE')
            ->assertJsonPath('data.unavailable_numbers.0.number', '34')
            ->assertJsonPath('data.unavailable_numbers.0.reason', 'closed')
            ->assertJsonPath('data.unavailable_numbers.0.blocked_by', 'hot_first_digit');

        $this->assertDatabaseCount('bets', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);

        $wallet->refresh();
        $this->assertEquals(100_000, $wallet->balance);
    }

    public function test_untagged_entry_on_hot_first_digit_is_rejected(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot(3);

        // An old client build sends no origin at all — it must fail closed.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([['number' => 34, 'amount' => 1000]]))
            ->assertStatus(422)
            ->assertJsonPath('data.unavailable_numbers.0.blocked_by', 'hot_first_digit');

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_double_on_hot_first_digit_is_accepted(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot(3);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([['number' => 33, 'amount' => 1000]]))
            ->assertStatus(201);

        $this->assertDatabaseCount('bets', 1);
    }

    public function test_zero_zero_is_treated_as_a_double(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot(0);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([['number' => 0, 'amount' => 1000]]))
            ->assertStatus(201);

        $this->assertDatabaseCount('bets', 1);
    }

    public function test_paired_reverse_bet_on_hot_first_digit_is_accepted(): void
    {
        $this->seedOddSetting();
        [, $wallet, $token] = $this->makeUserWithWallet();
        $this->markHot(3);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([
                ['number' => 34, 'amount' => 500, 'origin' => 'reverse'],
                ['number' => 43, 'amount' => 500, 'origin' => 'reverse'],
            ]))
            ->assertStatus(201);

        $this->assertDatabaseCount('bets', 1);
        $wallet->refresh();
        $this->assertEquals(99_000, $wallet->balance);
    }

    public function test_reverse_leg_without_its_mirror_is_rejected(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot(3);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([
                ['number' => 34, 'amount' => 500, 'origin' => 'reverse'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('data.unavailable_numbers.0.blocked_by', 'reverse_unpaired')
            ->assertJsonPath('errors.bet_numbers.0', 'R bet 34 needs 43 on the same slip for the same amount.');

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_reverse_pair_with_unequal_amounts_is_rejected(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot(3);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([
                ['number' => 34, 'amount' => 500, 'origin' => 'reverse'],
                ['number' => 43, 'amount' => 300, 'origin' => 'reverse'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('data.unavailable_numbers.0.blocked_by', 'reverse_unpaired');

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_direct_stake_cannot_ride_along_with_a_valid_reverse_pair(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot(3);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([
                ['number' => 34, 'amount' => 500, 'origin' => 'reverse'],
                ['number' => 43, 'amount' => 500, 'origin' => 'reverse'],
                ['number' => 34, 'amount' => 100, 'origin' => 'direct'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('data.unavailable_numbers.0.number', '34')
            ->assertJsonPath('data.unavailable_numbers.0.blocked_by', 'hot_first_digit');

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_duplicate_reverse_entries_pair_on_their_summed_amounts(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot(3);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([
                ['number' => 34, 'amount' => 250, 'origin' => 'reverse'],
                ['number' => 34, 'amount' => 250, 'origin' => 'reverse'],
                ['number' => 43, 'amount' => 500, 'origin' => 'reverse'],
            ]))
            ->assertStatus(201);

        $this->assertDatabaseCount('bets', 1);
    }

    public function test_reverse_pairing_is_not_enforced_off_a_hot_digit(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot(3);

        // Digit 5 is not hot, so a lone R leg on 56 is nobody's business.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([
                ['number' => 56, 'amount' => 500, 'origin' => 'reverse'],
            ]))
            ->assertStatus(201);

        $this->assertDatabaseCount('bets', 1);
    }

    public function test_second_digit_hot_does_not_block_the_number(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot(3);

        // 43 starts with 4; only its SECOND digit is hot.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([['number' => 43, 'amount' => 1000]]))
            ->assertStatus(201);

        $this->assertDatabaseCount('bets', 1);
    }

    public function test_hot_digits_are_scoped_to_their_period(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot(3, ['target_opentime' => '12:01:00']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([['number' => 34, 'amount' => 1000]]))
            ->assertStatus(201);

        $this->assertDatabaseCount('bets', 1);
    }

    public function test_hot_digits_are_scoped_to_their_currency(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot(3, ['currency' => 'THB']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([['number' => 34, 'amount' => 1000]]))
            ->assertStatus(201);

        $this->assertDatabaseCount('bets', 1);
    }

    public function test_three_d_bets_are_never_touched_by_hot_first_digits(): void
    {
        $this->seedOddSetting(BetType::THREE_D);
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot(3);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', [
                'bet_type' => '3D',
                'currency' => 'MMK',
                'bet_numbers' => [['number' => 345, 'amount' => 1000]],
            ])
            ->assertStatus(201);

        $this->assertDatabaseCount('bets', 1);
    }

    public function test_a_closed_number_is_reported_once_and_keeps_its_own_reason(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot(3);

        NumberControl::factory()->closed()->create([
            'number' => 34,
            'target_opentime' => '16:30:00',
            'stock_date' => $this->today(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([['number' => 34, 'amount' => 1000]]))
            ->assertStatus(422)
            ->assertJsonPath('data.unavailable_numbers.0.reason', 'closed')
            ->assertJsonPath('errors.bet_numbers.0', 'Number 34 is closed for this period.');

        // The closed check wins; the hot-digit pass must not add a second entry.
        $this->assertCount(1, $response->json('data.unavailable_numbers'));
    }

    public function test_closed_numbers_endpoint_exposes_hot_first_digits(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot(3);
        $this->markHot(7);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/closed-numbers?bet_type=2D&currency=MMK&target_opentime=16:30:00')
            ->assertStatus(200)
            ->assertJsonPath('data.hot_first_digits', ['3', '7']);
    }

    public function test_origin_must_be_direct_or_reverse(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([
                ['number' => 34, 'amount' => 1000, 'origin' => 'R'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('bet_numbers.0.origin');
    }
}
