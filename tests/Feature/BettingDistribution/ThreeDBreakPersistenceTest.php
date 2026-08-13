<?php

namespace Tests\Feature\BettingDistribution;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Models\NumberControl;
use App\Models\OddSetting;
use App\Models\TemporaryOddAdjustment;
use App\Models\ThreeDResult;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A 3D draw runs for days, so a break has to hold until the result is entered —
 * not until midnight.
 */
class ThreeDBreakPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        OddSetting::query()->updateOrCreate([
            'bet_type' => BetType::THREE_D,
            'currency' => Currency::MMK,
            'user_type' => OddSettingUserType::USER,
        ], [
            'odd' => '500.00',
            'is_active' => true,
        ]);
    }

    private function adminToken(): string
    {
        return User::factory()->admin()->create()->createToken('auth_token')->plainTextToken;
    }

    private function bettorToken(int $balance = 500_000): string
    {
        $user = User::factory()->normalUser()->create();
        Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => $balance,
            'currency' => Currency::MMK,
            'currency_locked_at' => now(),
            'bank_name' => 'KBZ',
            'account_name' => 'Test User',
            'account_number' => '1234567890',
        ]);

        return $user->createToken('auth_token')->plainTextToken;
    }

    private function break3D(int $number, array $overrides = []): void
    {
        $this->switchUser();

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson('/api/v1/admin/betting-distribution/number-controls', array_merge([
                'stock_date' => Carbon::now('Asia/Bangkok')->toDateString(),
                'bet_type' => '3D',
                'currency' => 'MMK',
                'controls' => [['number' => $number, 'action' => 'close']],
            ], $overrides))
            ->assertStatus(200);
    }

    /** Sanctum caches the resolved user on the guard, so drop it between tokens. */
    private function switchUser(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function placeBet(string $token, int $number, int $amount = 1_000): \Illuminate\Testing\TestResponse
    {
        $this->switchUser();

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/bets', [
                'bet_type' => '3D',
                'currency' => 'MMK',
                'security_pin' => '123456',
                'bet_numbers' => [['number' => $number, 'amount' => $amount]],
            ]);
    }

    public function test_a_break_still_blocks_bets_days_later_within_the_same_draw(): void
    {
        ThreeDResult::create(['stock_date' => Carbon::now()->subDays(2)->toDateString(), 'threed' => '482']);

        $this->break3D(456);

        $this->travel(3)->days();

        $this->placeBet($this->bettorToken(), 456)
            ->assertStatus(422)
            ->assertJsonPath('errors.bet_numbers.0', 'Number 456 is closed for this period.');

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_a_break_holds_when_no_result_has_ever_been_entered(): void
    {
        $this->break3D(789);

        $this->travel(5)->days();

        $this->placeBet($this->bettorToken(), 789)->assertStatus(422);
    }

    public function test_a_break_stops_applying_once_the_draw_is_settled(): void
    {
        ThreeDResult::create(['stock_date' => Carbon::now()->subDays(2)->toDateString(), 'threed' => '482']);

        $this->break3D(456);

        $this->travel(1)->days();

        // The result closes the draw the break belonged to; the next draw is clean.
        ThreeDResult::create(['stock_date' => Carbon::now()->toDateString(), 'threed' => '777']);

        $this->travel(1)->days();

        $this->placeBet($this->bettorToken(), 456)->assertStatus(201);
    }

    public function test_reopening_clears_the_break_for_the_whole_draw(): void
    {
        ThreeDResult::create(['stock_date' => Carbon::now()->subDays(2)->toDateString(), 'threed' => '482']);

        $this->break3D(456);

        $this->travel(2)->days();

        $this->switchUser();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson('/api/v1/admin/betting-distribution/number-controls/reopen', [
                'stock_date' => Carbon::now('Asia/Bangkok')->toDateString(),
                'bet_type' => '3D',
                'currency' => 'MMK',
                'numbers' => [456],
            ])
            ->assertStatus(200);

        $this->placeBet($this->bettorToken(), 456)->assertStatus(201);
    }

    public function test_a_sales_limit_counts_volume_from_every_day_of_the_draw(): void
    {
        ThreeDResult::create(['stock_date' => Carbon::now()->subDays(1)->toDateString(), 'threed' => '482']);

        $this->break3D(321, [
            'controls' => [['number' => 321, 'action' => 'limit', 'sales_limit' => '5000']],
        ]);

        $bettor = $this->bettorToken();

        $this->placeBet($bettor, 321, 3_000)->assertStatus(201);

        $this->travel(2)->days();

        // 3,000 already sold on an earlier day of the same draw — 3,000 more busts it.
        $this->placeBet($bettor, 321, 3_000)->assertStatus(422);

        $this->placeBet($bettor, 321, 2_000)->assertStatus(201);
    }

    public function test_the_board_keeps_showing_the_control_on_later_days(): void
    {
        ThreeDResult::create(['stock_date' => Carbon::now()->subDays(1)->toDateString(), 'threed' => '482']);

        $this->break3D(456);

        $this->travel(3)->days();

        $this->switchUser();
        $items = $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->getJson('/api/v1/admin/betting-distribution/three-d?currency=MMK')
            ->assertStatus(200)
            ->json('data.items');

        $keyed = [];
        foreach ($items as $item) {
            $keyed[$item['number']] = $item;
        }

        $this->assertTrue($keyed[456]['is_closed']);
        $this->assertTrue($keyed[456]['has_control']);
    }

    public function test_a_temporary_odd_applies_across_the_whole_draw(): void
    {
        ThreeDResult::create(['stock_date' => Carbon::now()->subDays(1)->toDateString(), 'threed' => '482']);

        $this->switchUser();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson('/api/v1/admin/betting-distribution/adjust-odds', [
                'stock_date' => Carbon::now('Asia/Bangkok')->toDateString(),
                'bet_type' => '3D',
                'currency' => 'MMK',
                'adjustments' => [['number' => 250, 'temp_odd' => '300.00']],
            ])
            ->assertStatus(200);

        $this->travel(3)->days();

        $this->placeBet($this->bettorToken(), 250, 1_000)->assertStatus(201);

        $this->assertDatabaseHas('bet_numbers', [
            'number' => 250,
            'odd' => '300.00',
        ]);
    }

    public function test_settling_a_result_retires_the_controls_of_the_draw_it_closes(): void
    {
        ThreeDResult::create(['stock_date' => Carbon::now()->subDays(2)->toDateString(), 'threed' => '482']);

        $this->break3D(456);

        $this->travel(1)->days();

        $this->switchUser();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson('/api/v1/admin/three-d-results', [
                'stock_date' => Carbon::now('Asia/Bangkok')->toDateString(),
                'threed' => '777',
            ])
            ->assertSuccessful();

        $this->assertSame(0, NumberControl::query()->where('bet_type', '3D')->count());
        $this->assertSame(0, TemporaryOddAdjustment::query()->where('bet_type', '3D')->count());
    }
}
