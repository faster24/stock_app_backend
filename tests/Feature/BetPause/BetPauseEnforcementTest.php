<?php

namespace Tests\Feature\BetPause;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Models\BetPause;
use App\Models\OddSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Bet\BetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BetPauseEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function seedOddSetting(Currency $currency = Currency::MMK, BetType $betType = BetType::TWO_D): void
    {
        OddSetting::query()->updateOrCreate([
            'bet_type' => $betType,
            'currency' => $currency,
            'user_type' => OddSettingUserType::USER,
        ], [
            'odd' => $betType === BetType::TWO_D ? '80.00' : '500.00',
            'is_active' => true,
        ]);
    }

    private function makeUserWithWallet(Currency $currency = Currency::MMK): array
    {
        $user = User::factory()->normalUser()->create();
        Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100_000,
            'currency' => $currency,
            'currency_locked_at' => now(),
            'bank_name' => 'KBZ',
            'account_name' => 'Test User',
            'account_number' => '1234567890',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        return [$user, $token];
    }

    private function betPayload(array $overrides = []): array
    {
        return array_merge([
            'bet_type' => '2D',
            'currency' => 'MMK',
            'target_opentime' => '12:01:00',
            'security_pin' => '123456',
            'bet_numbers' => [['number' => 23, 'amount' => 1000]],
        ], $overrides);
    }

    private function activePause(?string $message = 'Betting paused before the draw.'): BetPause
    {
        return BetPause::query()->create([
            'bet_type' => '2D',
            'is_enabled' => true,
            'pause_from' => Carbon::now()->subMinute(),
            'message' => $message,
        ]);
    }

    public function test_active_pause_rejects_bets_for_both_currencies(): void
    {
        $this->seedOddSetting(Currency::MMK);
        $this->seedOddSetting(Currency::THB);
        [, $mmkToken] = $this->makeUserWithWallet(Currency::MMK);
        [, $thbToken] = $this->makeUserWithWallet(Currency::THB);

        $this->activePause();

        $this->withHeader('Authorization', 'Bearer '.$mmkToken)
            ->postJson('/api/v1/bets', $this->betPayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.bet_type.0', 'Betting paused before the draw.');

        // Sanctum caches the resolved user on the guard within a test; reset before switching tokens.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$thbToken)
            ->postJson('/api/v1/bets', $this->betPayload(['currency' => 'THB']))
            ->assertStatus(422)
            ->assertJsonPath('errors.bet_type.0', 'Betting paused before the draw.');

        $this->assertDatabaseCount('bets', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_active_pause_blocks_every_2d_period(): void
    {
        $this->seedOddSetting();
        [, $token] = $this->makeUserWithWallet();

        $this->activePause();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload(['target_opentime' => '16:30:00']))
            ->assertStatus(422);
    }

    public function test_pause_now_set_via_admin_api_with_yangon_offset_blocks_bets_immediately(): void
    {
        $this->seedOddSetting();
        [, $userToken] = $this->makeUserWithWallet();
        $adminToken = User::factory()->admin()->create()->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->putJson('/api/v1/admin/bet-pauses', [
                'bet_type' => '2D',
                'is_enabled' => true,
                'pause_from' => Carbon::now('Asia/Yangon')->toIso8601String(),
                'message' => 'Paused right now.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.bet_pause.status', 'paused');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$userToken)
            ->postJson('/api/v1/bets', $this->betPayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.bet_type.0', 'Paused right now.');
    }

    public function test_future_scheduled_pause_still_accepts_bets(): void
    {
        $this->seedOddSetting();
        [, $token] = $this->makeUserWithWallet();

        BetPause::query()->create([
            'bet_type' => '2D',
            'is_enabled' => true,
            'pause_from' => Carbon::now()->addHour(),
            'message' => 'Pausing soon.',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload())
            ->assertStatus(201);
    }

    public function test_default_message_is_used_when_none_set(): void
    {
        $this->seedOddSetting();
        [, $token] = $this->makeUserWithWallet();

        $this->activePause(null);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.bet_type.0', 'Betting is currently paused.');
    }

    public function test_3d_bets_are_unaffected_by_2d_pause(): void
    {
        $this->seedOddSetting(Currency::MMK, BetType::THREE_D);
        [, $token] = $this->makeUserWithWallet();

        $this->activePause();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', [
                'bet_type' => '3D',
                'currency' => 'MMK',
                'security_pin' => '123456',
                'bet_numbers' => [['number' => 456, 'amount' => 1000]],
            ])
            ->assertStatus(201);
    }

    public function test_update_of_2d_bet_is_rejected_while_paused(): void
    {
        $this->seedOddSetting();
        [$user] = $this->makeUserWithWallet();
        $service = app(BetService::class);

        $bet = $service->createForUser($user->id, $this->betPayload());

        $this->activePause();

        try {
            $service->updateForUser($user->id, $bet->id, [
                'bet_numbers' => [['number' => 45, 'amount' => 1000]],
            ]);
            $this->fail('Expected ValidationException for update while paused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bet_type', $exception->errors());
        }
    }

    public function test_resumed_betting_accepts_bets_again(): void
    {
        $this->seedOddSetting();
        [, $token] = $this->makeUserWithWallet();

        $pause = $this->activePause();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload())
            ->assertStatus(422);

        $pause->update(['is_enabled' => false, 'pause_from' => null, 'message' => null]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', $this->betPayload())
            ->assertStatus(201);
    }
}
