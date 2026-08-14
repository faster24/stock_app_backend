<?php

namespace Tests\Feature\BettingDistribution;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Models\Bet;
use App\Models\NumberControl;
use App\Models\OddSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Bet\BetService;
use App\Services\BettingDistribution\ThreeDDrawScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NumberControlEnforcementTest extends TestCase
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
            'security_pin' => '123456',
            'bet_numbers' => $betNumbers,
        ], $overrides);
    }

    public function test_bet_on_closed_number_is_rejected_without_side_effects(): void
    {
        $this->seedOddSetting();
        [, $wallet, $token] = $this->makeUserWithWallet();

        NumberControl::factory()->closed()->create([
            'number' => 23,
            'target_opentime' => '16:30:00',
            'stock_date' => $this->today(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([['number' => 23, 'amount' => 1000]]))
            ->assertStatus(422)
            ->assertJsonPath('errors.bet_numbers.0', 'Number 23 is closed for this period.');

        $this->assertDatabaseCount('bets', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);

        $wallet->refresh();
        $this->assertEquals(100_000, $wallet->balance);
    }

    public function test_mixed_bet_with_one_closed_number_is_rejected_whole(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();

        NumberControl::factory()->closed()->create([
            'number' => 23,
            'target_opentime' => '16:30:00',
            'stock_date' => $this->today(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([
                ['number' => 7, 'amount' => 1000],
                ['number' => 23, 'amount' => 1000],
            ]))
            ->assertStatus(422);

        $this->assertDatabaseCount('bets', 0);
        $this->assertDatabaseCount('bet_numbers', 0);
    }

    public function test_sales_limit_boundary_allows_exact_fill_and_rejects_excess(): void
    {
        $this->seedOddSetting();
        [$existingUser] = $this->makeUserWithWallet();
        [, , $token] = $this->makeUserWithWallet();

        NumberControl::factory()->limited('10000.00')->create([
            'number' => 45,
            'target_opentime' => '16:30:00',
            'stock_date' => $this->today(),
        ]);

        app(BetService::class)->createForUser($existingUser->id, $this->betPayload([
            ['number' => 45, 'amount' => 6000],
        ]));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([['number' => 45, 'amount' => 4001]]))
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.bet_numbers.0',
                'Number 45 exceeds the sales limit for this period.'
            );

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([['number' => 45, 'amount' => 4000]]))
            ->assertStatus(201);
    }

    public function test_duplicate_numbers_in_one_bet_are_summed_against_the_limit(): void
    {
        $this->seedOddSetting();
        [$user] = $this->makeUserWithWallet();

        NumberControl::factory()->limited('10000.00')->create([
            'number' => 45,
            'target_opentime' => '16:30:00',
            'stock_date' => $this->today(),
        ]);

        try {
            app(BetService::class)->createForUser($user->id, $this->betPayload([
                ['number' => 45, 'amount' => 6000],
                ['number' => 45, 'amount' => 6000],
            ]));
            $this->fail('Expected ValidationException for summed duplicate numbers.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bet_numbers', $exception->errors());
        }

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_control_scope_does_not_leak_across_period_currency_or_date(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();

        // Different period
        NumberControl::factory()->closed()->create([
            'number' => 23,
            'target_opentime' => '12:01:00',
            'stock_date' => $this->today(),
        ]);
        // Different currency
        NumberControl::factory()->closed()->create([
            'number' => 24,
            'currency' => Currency::THB,
            'target_opentime' => '16:30:00',
            'stock_date' => $this->today(),
        ]);
        // Different date
        NumberControl::factory()->closed()->create([
            'number' => 25,
            'target_opentime' => '16:30:00',
            'stock_date' => Carbon::now()->subDay()->toDateString(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([
                ['number' => 23, 'amount' => 1000],
                ['number' => 24, 'amount' => 1000],
                ['number' => 25, 'amount' => 1000],
            ]))
            ->assertStatus(201);
    }

    public function test_reopened_number_accepts_bets_again(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();

        $control = NumberControl::factory()->closed()->create([
            'number' => 23,
            'target_opentime' => '16:30:00',
            'stock_date' => $this->today(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([['number' => 23, 'amount' => 1000]]))
            ->assertStatus(422);

        $control->delete();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([['number' => 23, 'amount' => 1000]]))
            ->assertStatus(201);
    }

    public function test_update_onto_closed_number_is_rejected_and_self_volume_is_excluded(): void
    {
        $this->seedOddSetting();
        [$user] = $this->makeUserWithWallet();
        $service = app(BetService::class);

        NumberControl::factory()->limited('10000.00')->create([
            'number' => 45,
            'target_opentime' => '16:30:00',
            'stock_date' => $this->today(),
        ]);

        $bet = $service->createForUser($user->id, $this->betPayload([
            ['number' => 45, 'amount' => 10000],
        ]));

        // Unchanged resubmit passes: the bet's own volume is excluded from the sum.
        $updated = $service->updateForUser($user->id, $bet->id, [
            'bet_numbers' => [['number' => 45, 'amount' => 10000]],
        ]);
        $this->assertNotNull($updated);

        // Increasing past the limit fails.
        try {
            $service->updateForUser($user->id, $bet->id, [
                'bet_numbers' => [['number' => 45, 'amount' => 10001]],
            ]);
            $this->fail('Expected ValidationException for exceeding the limit on update.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bet_numbers', $exception->errors());
        }

        // Moving onto a closed number fails.
        NumberControl::factory()->closed()->create([
            'number' => 55,
            'target_opentime' => '16:30:00',
            'stock_date' => $this->today(),
        ]);

        try {
            $service->updateForUser($user->id, $bet->id, [
                'bet_numbers' => [['number' => 55, 'amount' => 1000]],
            ]);
            $this->fail('Expected ValidationException for a closed number on update.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('closed', $exception->errors()['bet_numbers'][0]);
        }
    }

    public function test_closed_3d_number_blocks_3d_bet(): void
    {
        $this->seedOddSetting(BetType::THREE_D);
        [, , $token] = $this->makeUserWithWallet();

        // A 3D control is anchored to the open draw, not to a calendar day.
        NumberControl::factory()->closed()->create([
            'bet_type' => BetType::THREE_D,
            'number' => 456,
            'target_opentime' => '',
            'stock_date' => app(ThreeDDrawScope::class)->anchorDate(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', [
                'bet_type' => '3D',
                'currency' => 'MMK',
                'security_pin' => '123456',
                'bet_numbers' => [['number' => 456, 'amount' => 1000]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.bet_numbers.0', 'Number 456 is closed for this period.');

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_closed_numbers_endpoint_returns_state_for_users(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();

        NumberControl::factory()->closed()->create([
            'number' => 23,
            'target_opentime' => '16:30:00',
            'stock_date' => $this->today(),
        ]);
        NumberControl::factory()->limited('10000.00')->create([
            'number' => 45,
            'target_opentime' => '16:30:00',
            'stock_date' => $this->today(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/closed-numbers?bet_type=2D&currency=MMK&target_opentime=16:30:00&stock_date='.$this->today())
            ->assertStatus(200)
            ->assertJsonPath('data.closed.0', 23)
            ->assertJsonPath('data.limited.0.number', 45)
            ->assertJsonPath('data.limited.0.sales_limit', '10000.00')
            ->assertJsonPath('data.limited.0.remaining', '10000.00')
            ->assertJsonMissingPath('data.limited.0.sold');
    }

    public function test_accepted_bet_on_uncontrolled_number_is_unaffected(): void
    {
        $this->seedOddSetting();
        [, , $token] = $this->makeUserWithWallet();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload([['number' => 7, 'amount' => 1000]]))
            ->assertStatus(201);

        $this->assertSame(1, Bet::query()->count());
    }
}
