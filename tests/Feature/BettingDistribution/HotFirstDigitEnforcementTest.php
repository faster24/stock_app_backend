<?php

namespace Tests\Feature\BettingDistribution;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Models\Bet;
use App\Models\DigitControl;
use App\Models\NumberControl;
use App\Models\OddSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Bet\BetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Hot digits: a number with EITHER digit hot must ride with its reverse, and
 * every such number carries one amount — on the slip and across the draw.
 */
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

    private function markHot(array $digits, array $overrides = []): void
    {
        foreach ($digits as $digit) {
            DigitControl::query()->create(array_merge([
                'bet_type' => '2D',
                'currency' => 'MMK',
                'digit' => $digit,
                'target_opentime' => '16:30:00',
                'stock_date' => $this->today(),
            ], $overrides));
        }
    }

    /** @param array<int, int> $amounts number => amount, one line each */
    private function lines(array $amounts): array
    {
        $lines = [];
        foreach ($amounts as $number => $amount) {
            $lines[] = ['number' => $number, 'amount' => $amount];
        }

        return $lines;
    }

    private function bet(string $token, array $lines, array $overrides = []): TestResponse
    {
        // Sanctum caches the resolved user on the guard within one test.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', array_merge([
                'bet_type' => '2D',
                'currency' => 'MMK',
                'target_opentime' => '16:30:00',
                'bet_numbers' => $lines,
            ], $overrides));
    }

    private function blockedBy(TestResponse $response): array
    {
        $result = [];
        foreach ($response->json('data.unavailable_numbers') ?? [] as $entry) {
            $result[$entry['number']] = $entry['blocked_by'] ?? $entry['reason'];
        }
        ksort($result);

        return $result;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOddSetting();
    }

    // ── the slip ────────────────────────────────────────────────────────────

    public function test_a_number_and_its_reverse_at_the_same_amount_are_accepted(): void
    {
        [, $wallet, $token] = $this->makeUserWithWallet();
        $this->markHot([6, 7, 8]);

        $this->bet($token, $this->lines([61 => 100, 16 => 100]))->assertStatus(201);

        $this->assertEquals(99_800, $wallet->refresh()->balance);
    }

    public function test_the_users_example_is_accepted_when_every_amount_matches(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6, 7, 8]);

        $this->bet($token, $this->lines([61 => 100, 16 => 100, 67 => 100, 76 => 100, 70 => 100, 7 => 100]))
            ->assertStatus(201);
    }

    public function test_differing_amounts_block_every_covered_number_without_side_effects(): void
    {
        [, $wallet, $token] = $this->makeUserWithWallet();
        $this->markHot([6, 7, 8]);

        $response = $this->bet($token, $this->lines([61 => 100, 16 => 100, 67 => 200, 76 => 200]))
            ->assertStatus(422)
            ->assertJsonPath('data.code', 'BET_NUMBERS_UNAVAILABLE')
            ->assertJsonPath('data.unavailable_numbers.0.reason', 'closed')
            ->assertJsonPath('errors.bet_numbers.0', 'Numbers with 6, 7, 8 must all be bet at the same amount.');

        $this->assertSame(
            ['16' => 'amount_mismatch', '61' => 'amount_mismatch', '67' => 'amount_mismatch', '76' => 'amount_mismatch'],
            $this->blockedBy($response),
        );
        // One sentence for the group, not one per number.
        $this->assertCount(1, $response->json('errors.bet_numbers'));

        $this->assertDatabaseCount('bets', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertEquals(100_000, $wallet->refresh()->balance);
    }

    public function test_a_covered_number_without_its_reverse_is_refused(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6, 7, 8]);

        $response = $this->bet($token, $this->lines([61 => 500]))
            ->assertStatus(422)
            ->assertJsonPath('errors.bet_numbers.0', 'Number 61 needs 16 on the same slip for the same amount.');

        $this->assertSame(['61' => 'reverse_unpaired'], $this->blockedBy($response));
    }

    public function test_only_the_number_missing_its_reverse_is_listed(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6, 7, 8]);

        $response = $this->bet($token, $this->lines([61 => 100, 16 => 100, 70 => 100]))->assertStatus(422);

        $this->assertSame(['70' => 'reverse_unpaired'], $this->blockedBy($response));
    }

    public function test_a_hot_second_digit_is_covered_too(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6]);

        // 16 starts with 1; its 6 still makes it covered.
        $response = $this->bet($token, $this->lines([16 => 100]))->assertStatus(422);
        $this->assertSame(['16' => 'reverse_unpaired'], $this->blockedBy($response));
    }

    public function test_a_double_is_its_own_reverse(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6]);

        $this->bet($token, $this->lines([66 => 100]))->assertStatus(201);
    }

    public function test_a_double_must_match_the_group_amount(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6]);

        $response = $this->bet($token, $this->lines([61 => 100, 16 => 100, 66 => 500]))->assertStatus(422);

        $this->assertSame(
            ['16' => 'amount_mismatch', '61' => 'amount_mismatch', '66' => 'amount_mismatch'],
            $this->blockedBy($response),
        );
    }

    public function test_how_the_numbers_were_entered_does_not_matter(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6]);

        // Two plain Direct lines pass exactly like an R pair.
        $this->bet($token, [
            ['number' => 61, 'amount' => 100, 'origin' => 'direct'],
            ['number' => 16, 'amount' => 100],
        ])->assertStatus(201);

        // And a reverse tag does not rescue a lone number.
        $this->bet($token, [['number' => 62, 'amount' => 100, 'origin' => 'reverse']])->assertStatus(422);
    }

    public function test_duplicate_lines_are_summed_per_number(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6]);

        $this->bet($token, [
            ['number' => 61, 'amount' => 50],
            ['number' => 61, 'amount' => 50],
            ['number' => 16, 'amount' => 100],
        ])->assertStatus(201);
    }

    public function test_numbers_without_a_hot_digit_are_left_alone(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6]);

        $this->bet($token, $this->lines([61 => 100, 16 => 100, 12 => 500, 45 => 30]))->assertStatus(201);
    }

    // ── the draw ────────────────────────────────────────────────────────────

    public function test_later_slips_in_the_draw_must_use_the_same_amount(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6, 7, 8]);

        $this->bet($token, $this->lines([61 => 100, 16 => 100]))->assertStatus(201);

        $response = $this->bet($token, $this->lines([67 => 200, 76 => 200]))
            ->assertStatus(422)
            ->assertJsonPath('errors.bet_numbers.0', 'Numbers with 6, 7, 8 must all be bet at the same amount (100 this draw).');
        $this->assertSame(['67' => 'amount_mismatch', '76' => 'amount_mismatch'], $this->blockedBy($response));

        $this->bet($token, $this->lines([67 => 100, 76 => 100]))->assertStatus(201);
    }

    public function test_repeating_the_same_slip_is_not_read_as_a_doubled_amount(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6]);

        $this->bet($token, $this->lines([61 => 100, 16 => 100]))->assertStatus(201);
        $this->bet($token, $this->lines([61 => 100, 16 => 100]))->assertStatus(201);
    }

    public function test_another_players_amount_does_not_bind(): void
    {
        [, , $first] = $this->makeUserWithWallet();
        [, , $second] = $this->makeUserWithWallet();
        $this->markHot([6]);

        $this->bet($first, $this->lines([61 => 100, 16 => 100]))->assertStatus(201);
        $this->bet($second, $this->lines([61 => 300, 16 => 300]))->assertStatus(201);
    }

    public function test_another_open_time_is_another_draw(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6]);
        $this->markHot([6], ['target_opentime' => '12:01:00']);

        $this->bet($token, $this->lines([61 => 100, 16 => 100]), ['target_opentime' => '12:01:00'])->assertStatus(201);
        $this->bet($token, $this->lines([61 => 300, 16 => 300]))->assertStatus(201);
    }

    public function test_a_rejected_bet_does_not_set_the_draw_amount(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6]);

        $this->bet($token, $this->lines([61 => 100, 16 => 100]))->assertStatus(201);
        Bet::query()->update(['status' => BetStatus::REJECTED]);

        $this->bet($token, $this->lines([61 => 300, 16 => 300]))->assertStatus(201);
    }

    public function test_mixed_history_from_before_the_digit_went_hot_enforces_the_slip_only(): void
    {
        [, , $token] = $this->makeUserWithWallet();

        // Placed while nothing was hot, at two different amounts.
        $this->bet($token, $this->lines([61 => 100, 16 => 100]))->assertStatus(201);
        $this->bet($token, $this->lines([67 => 200, 76 => 200]))->assertStatus(201);

        $this->markHot([6, 7]);

        $this->bet($token, $this->lines([70 => 300, 7 => 300]))->assertStatus(201);
        $this->bet($token, $this->lines([70 => 300, 7 => 400]))->assertStatus(422);
    }

    public function test_an_update_is_checked_against_the_players_other_bets(): void
    {
        [$user, , $token] = $this->makeUserWithWallet();
        $this->markHot([6]);

        $this->bet($token, $this->lines([61 => 100, 16 => 100]))->assertStatus(201);
        $betId = Bet::query()->value('id');

        // Its own lines are excluded, so it may change its amount on its own…
        app(BetService::class)->updateForUser($user->id, $betId, [
            'bet_numbers' => $this->lines([61 => 200, 16 => 200]),
        ]);

        // …but not away from another bet in the same draw.
        $this->bet($token, $this->lines([62 => 200, 26 => 200]))->assertStatus(201);

        $this->expectException(ValidationException::class);
        app(BetService::class)->updateForUser($user->id, $betId, [
            'bet_numbers' => $this->lines([61 => 300, 16 => 300]),
        ]);
    }

    // ── unchanged contracts ─────────────────────────────────────────────────

    public function test_a_closed_number_is_reported_once_with_its_own_reason(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6]);

        NumberControl::factory()->closed()->create([
            'number' => 61,
            'target_opentime' => '16:30:00',
            'stock_date' => $this->today(),
        ]);

        $response = $this->bet($token, $this->lines([61 => 100]))
            ->assertStatus(422)
            ->assertJsonPath('errors.bet_numbers.0', 'Number 61 is closed for this period.');

        $this->assertSame(['61' => 'closed'], $this->blockedBy($response));
    }

    public function test_three_d_bets_are_never_touched(): void
    {
        $this->seedOddSetting(BetType::THREE_D);
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([6]);

        $this->bet($token, $this->lines([612 => 1000]), ['bet_type' => '3D', 'target_opentime' => null])
            ->assertStatus(201);
    }

    public function test_nothing_is_enforced_when_no_digit_is_hot(): void
    {
        [, , $token] = $this->makeUserWithWallet();

        $this->bet($token, $this->lines([61 => 100, 67 => 200]))->assertStatus(201);
    }

    public function test_closed_numbers_endpoint_exposes_the_hot_digits(): void
    {
        [, , $token] = $this->makeUserWithWallet();
        $this->markHot([3, 7]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            // Pinned: the endpoint defaults stock_date to the Bangkok date, which
            // runs a day ahead of the UTC test clock after 17:00 UTC.
            ->getJson('/api/v1/closed-numbers?bet_type=2D&currency=MMK&target_opentime=16:30:00&stock_date='.$this->today())
            ->assertStatus(200)
            ->assertJsonPath('data.hot_first_digits', ['3', '7']);
    }

    public function test_origin_must_still_be_direct_or_reverse(): void
    {
        [, , $token] = $this->makeUserWithWallet();

        $this->bet($token, [['number' => 34, 'amount' => 1000, 'origin' => 'R']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bet_numbers.0.origin');
    }
}
