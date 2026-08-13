<?php

namespace Tests\Feature\BettingDistribution;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Models\Bet;
use App\Models\BetNumber;
use App\Models\NumberControl;
use App\Models\OddSetting;
use App\Models\ThreeDResult;
use App\Models\User;
use App\Services\BettingDistribution\ThreeDDrawScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ThreeDDistributionTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/admin/betting-distribution/three-d';

    private function adminToken(): string
    {
        return User::factory()->admin()->create()->createToken('auth_token')->plainTextToken;
    }

    private function threeDBet(string $stockDate, int $number, int $amount, array $overrides = []): Bet
    {
        $bet = Bet::factory()->create(array_merge([
            'bet_type' => BetType::THREE_D,
            'currency' => Currency::MMK,
            'status' => BetStatus::ACCEPTED,
            'stock_date' => $stockDate,
            'target_opentime' => null,
            'total_amount' => number_format($amount, 2, '.', ''),
        ], $overrides));

        BetNumber::factory()->forBetWithNumber($bet, $number, $amount)->create();

        return $bet;
    }

    private function get3D(string $currency = 'MMK'): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->getJson(self::ENDPOINT.'?currency='.$currency);
    }

    /** Where 3D controls and temporary odds of the open draw are stored. */
    private function anchorDate(): string
    {
        return app(ThreeDDrawScope::class)->anchorDate();
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsByNumber(array $items): array
    {
        $keyed = [];
        foreach ($items as $item) {
            $keyed[$item['number']] = $item;
        }

        return $keyed;
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    public function test_non_admin_cannot_read_the_three_d_board(): void
    {
        $token = User::factory()->normalUser()->create()->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT)
            ->assertStatus(403);
    }

    public function test_invalid_currency_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->getJson(self::ENDPOINT.'?currency=USD')
            ->assertStatus(422)
            ->assertJsonPath('errors.currency.0', 'The selected currency is invalid.');
    }

    public function test_draw_window_starts_at_the_latest_result_date(): void
    {
        $today = Carbon::now('Asia/Bangkok')->toDateString();
        $resultDate = Carbon::now('Asia/Bangkok')->subDays(3)->toDateString();

        ThreeDResult::create(['stock_date' => $resultDate, 'threed' => '123']);

        // Settled draw — before the floor, must not appear.
        $this->threeDBet(Carbon::now('Asia/Bangkok')->subDays(5)->toDateString(), 111, 5_000);
        // On the floor day — inclusive, belongs to the open draw.
        $this->threeDBet($resultDate, 222, 2_000);
        $this->threeDBet($today, 333, 7_000);

        $response = $this->get3D()->assertStatus(200);

        $response->assertJsonPath('data.draw_window.from', $resultDate)
            ->assertJsonPath('data.draw_window.to', $today)
            ->assertJsonPath('data.latest_result_date', $resultDate)
            ->assertJsonPath('data.controls_anchor_date', $resultDate);

        $items = $this->itemsByNumber($response->json('data.items'));

        $this->assertArrayNotHasKey(111, $items);
        $this->assertSame('2000.00', $items[222]['volume']);
        $this->assertSame('7000.00', $items[333]['volume']);
        $this->assertSame('9000.00', $response->json('data.summary.total_bet_volume'));
    }

    public function test_all_bets_are_included_when_no_result_exists_yet(): void
    {
        $this->threeDBet(Carbon::now('Asia/Bangkok')->subDays(20)->toDateString(), 456, 1_500);

        $response = $this->get3D()->assertStatus(200);

        $response->assertJsonPath('data.draw_window.from', null)
            ->assertJsonPath('data.latest_result_date', null);

        $items = $this->itemsByNumber($response->json('data.items'));
        $this->assertSame('1500.00', $items[456]['volume']);
    }

    public function test_three_digit_numbers_are_aggregated_and_sorted_by_volume_desc(): void
    {
        $today = Carbon::now('Asia/Bangkok')->toDateString();

        $this->threeDBet($today, 999, 1_000);
        $this->threeDBet($today, 123, 4_000);
        $this->threeDBet($today, 123, 3_000);
        $this->threeDBet($today, 7, 2_000);

        $items = $this->get3D()->assertStatus(200)->json('data.items');

        $this->assertSame([123, 7, 999], array_column($items, 'number'));

        $keyed = $this->itemsByNumber($items);
        $this->assertSame('7000.00', $keyed[123]['volume']);
        $this->assertSame(2, $keyed[123]['count']);
    }

    public function test_only_pending_and_accepted_bets_of_the_matching_currency_count(): void
    {
        $today = Carbon::now('Asia/Bangkok')->toDateString();

        $this->threeDBet($today, 100, 1_000, ['status' => BetStatus::PENDING]);
        $this->threeDBet($today, 200, 1_000, ['status' => BetStatus::REJECTED]);
        $this->threeDBet($today, 300, 1_000, ['currency' => Currency::THB]);
        // 2D bet on the same number must never leak into the 3D board.
        $twoD = Bet::factory()->create([
            'bet_type' => BetType::TWO_D,
            'currency' => Currency::MMK,
            'status' => BetStatus::ACCEPTED,
            'stock_date' => $today,
            'target_opentime' => '16:30:00',
        ]);
        BetNumber::factory()->forBetWithNumber($twoD, 100, 9_000)->create();

        $items = $this->itemsByNumber($this->get3D()->assertStatus(200)->json('data.items'));

        $this->assertSame('1000.00', $items[100]['volume']);
        $this->assertArrayNotHasKey(200, $items);
        $this->assertArrayNotHasKey(300, $items);
    }

    public function test_payload_is_sparse_but_keeps_controlled_numbers(): void
    {
        $today = Carbon::now('Asia/Bangkok')->toDateString();

        $this->threeDBet($today, 555, 1_000);

        NumberControl::create([
            'bet_type' => '3D',
            'currency' => 'MMK',
            'number' => 888,
            'target_opentime' => '',
            'stock_date' => $this->anchorDate(),
            'is_closed' => false,
            'sales_limit' => '5000.00',
        ]);

        $items = $this->itemsByNumber($this->get3D()->assertStatus(200)->json('data.items'));

        $this->assertCount(2, $items);
        $this->assertArrayNotHasKey(1, $items);

        $this->assertTrue($items[888]['has_control']);
        $this->assertSame('5000.00', $items[888]['sales_limit']);
        $this->assertSame('5000.00', $items[888]['remaining']);
        $this->assertFalse($items[555]['has_control']);
    }

    public function test_remaining_reflects_volume_sold_against_a_limit(): void
    {
        $today = Carbon::now('Asia/Bangkok')->toDateString();

        $this->threeDBet($today, 777, 2_000);

        NumberControl::create([
            'bet_type' => '3D',
            'currency' => 'MMK',
            'number' => 777,
            'target_opentime' => '',
            'stock_date' => $this->anchorDate(),
            'is_closed' => false,
            'sales_limit' => '5000.00',
        ]);

        $items = $this->itemsByNumber($this->get3D()->assertStatus(200)->json('data.items'));

        $this->assertSame('3000.00', $items[777]['remaining']);
    }

    public function test_three_d_odds_can_be_adjusted_without_a_target_opentime_and_show_on_the_board(): void
    {
        $today = Carbon::now('Asia/Bangkok')->toDateString();

        $this->threeDBet($today, 321, 1_000);

        OddSetting::query()->updateOrCreate([
            'bet_type' => BetType::THREE_D,
            'currency' => Currency::MMK,
            'user_type' => OddSettingUserType::USER,
        ], [
            'odd' => '500.00',
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson('/api/v1/admin/betting-distribution/adjust-odds', [
                'stock_date' => $today,
                'bet_type' => '3D',
                'currency' => 'MMK',
                'adjustments' => [['number' => 321, 'temp_odd' => '450.00']],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('temporary_odd_adjustments', [
            'bet_type' => '3D',
            'currency' => 'MMK',
            'number' => 321,
            'target_opentime' => '',
            // Stored at the draw anchor so it outlives the day it was made on.
            'stock_date' => $this->anchorDate(),
        ]);

        $items = $this->itemsByNumber($this->get3D()->assertStatus(200)->json('data.items'));

        $this->assertTrue($items[321]['has_adjustment']);
        $this->assertSame('450.00', $items[321]['odd']);
    }
}
