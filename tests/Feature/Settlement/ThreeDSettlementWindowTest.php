<?php

namespace Tests\Feature\Settlement;

use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Models\ThreeDResult;
use App\Models\User;
use App\Services\Bet\BetSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The 3D window spans [previous result's stock_date, this result's stock_date],
 * both inclusive.
 *
 * The bounds used to exclude both end days, which orphaned any bet placed on a
 * result date: it fell outside that draw's window and then below the floor of
 * every later one, so it stayed ACCEPTED/OPEN with no run that would ever claim
 * it — and it was not even reported, since `skipped` only counts bets that were
 * in scope to begin with.
 */
class ThreeDSettlementWindowTest extends TestCase
{
    use RefreshDatabase;

    private function threeDBet(string $stockDate, int $number): Bet
    {
        $bet = Bet::factory()->for(User::factory()->normalUser())->create([
            'bet_type' => BetType::THREE_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::OPEN,
            'stock_date' => $stockDate,
        ]);

        $bet->betNumbers()->create(['number' => $number, 'amount' => 1000]);

        return $bet;
    }

    private function settle(string $stockDate, string $threed): array
    {
        $result = ThreeDResult::query()->create([
            'stock_date' => $stockDate,
            'threed' => $threed,
        ]);

        return app(BetSettlementService::class)->settleThreeDResult($result);
    }

    public function test_bet_placed_on_the_result_date_settles_in_that_run(): void
    {
        $bet = $this->threeDBet('2026-08-16', 123);

        $summary = $this->settle('2026-08-16', '123');

        $this->assertSame(1, $summary['won']);
        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'bet_result_status' => BetResultStatus::WON->value,
            'settled_result_history_id' => '3d-result-2026-08-16',
        ]);
    }

    public function test_bet_on_the_draw_day_is_claimed_even_when_it_loses(): void
    {
        // Betting was open on the draw day, so this bet carries the result's own date.
        $bet = $this->threeDBet('2026-08-16', 999);

        // Different number — the bet loses. The point is that it is claimed rather
        // than left hanging on the window boundary.
        $this->settle('2026-08-16', '123');

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'bet_result_status' => BetResultStatus::LOST->value,
        ]);
    }

    public function test_bet_placed_after_a_result_on_that_same_date_settles_at_the_next_draw(): void
    {
        // The holiday sequence: draw slips, result entered, betting reopened the same
        // day. Bets placed after that carry the result date and must not be orphaned.
        $this->settle('2026-09-01', '111');

        $bet = $this->threeDBet('2026-09-01', 222);

        $summary = $this->settle('2026-09-16', '222');

        $this->assertSame(1, $summary['won']);
        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'bet_result_status' => BetResultStatus::WON->value,
            'settled_result_history_id' => '3d-result-2026-09-16',
        ]);
    }

    public function test_already_settled_bets_are_not_resettled_when_windows_overlap(): void
    {
        $bet = $this->threeDBet('2026-09-01', 111);

        $this->settle('2026-09-01', '111');

        $settledFirst = Bet::query()->findOrFail($bet->id);
        $this->assertSame(BetResultStatus::WON, $settledFirst->bet_result_status);

        // The next window starts on 2026-09-01 again. The OPEN filter is what keeps
        // this bet out of it.
        $summary = $this->settle('2026-09-16', '111');

        $this->assertSame(0, $summary['settled']);

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'bet_result_status' => BetResultStatus::WON->value,
            'settled_result_history_id' => '3d-result-2026-09-01',
        ]);
    }

    public function test_bets_before_the_previous_result_date_stay_out_of_scope(): void
    {
        $stale = $this->threeDBet('2026-08-31', 111);

        $this->settle('2026-09-01', '999');

        // Claimed by the 1st, so it must not be revisited by the 16th.
        $this->assertDatabaseHas('bets', [
            'id' => $stale->id,
            'bet_result_status' => BetResultStatus::LOST->value,
            'settled_result_history_id' => '3d-result-2026-09-01',
        ]);

        $summary = $this->settle('2026-09-16', '111');

        $this->assertSame(0, $summary['settled']);
    }

    public function test_only_accepted_open_bets_are_settled(): void
    {
        $pending = Bet::factory()->for(User::factory()->normalUser())->create([
            'bet_type' => BetType::THREE_D,
            'status' => BetStatus::PENDING,
            'bet_result_status' => BetResultStatus::OPEN,
            'stock_date' => '2026-09-01',
        ]);
        $pending->betNumbers()->create(['number' => 111, 'amount' => 1000]);

        $summary = $this->settle('2026-09-01', '111');

        $this->assertSame(0, $summary['settled']);
        $this->assertDatabaseHas('bets', [
            'id' => $pending->id,
            'bet_result_status' => BetResultStatus::OPEN->value,
        ]);
    }
}
