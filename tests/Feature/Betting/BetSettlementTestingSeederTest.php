<?php

namespace Tests\Feature\Betting;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Models\Bet;
use App\Models\TwoDResult;
use Database\Seeders\BetSettlementTestingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BetSettlementTestingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_full_betting_flow_snapshot_and_is_idempotent(): void
    {
        $this->seed(BetSettlementTestingSeeder::class);

        $elevenAmResult = TwoDResult::query()
            ->where('history_id', 'settlement-test-2026-03-19-11-00')
            ->first();
        $noonResult = TwoDResult::query()
            ->where('history_id', 'settlement-test-2026-03-19-12-01')
            ->first();

        $this->assertNotNull($elevenAmResult);
        $this->assertNotNull($noonResult);

        $this->assertSame(1, Bet::query()->where('status', BetStatus::PENDING->value)->count());
        $this->assertSame(2, Bet::query()->where('status', BetStatus::ACCEPTED->value)->count());
        $this->assertSame(1, Bet::query()->where('status', BetStatus::REJECTED->value)->count());
        $this->assertSame(1, Bet::query()->where('status', BetStatus::REFUNDED->value)->count());

        $wonBet = Bet::query()->where('bet_result_status', BetResultStatus::WON->value)->first();
        $lostBet = Bet::query()->where('bet_result_status', BetResultStatus::LOST->value)->first();

        $this->assertNotNull($wonBet);
        $this->assertNotNull($lostBet);

        $this->assertSame('settlement-test-2026-03-19-11-00', $wonBet->settled_result_history_id);
        $this->assertSame('settlement-test-2026-03-19-11-00', $lostBet->settled_result_history_id);
        $this->assertNotNull($wonBet->settled_at);
        $this->assertNotNull($lostBet->settled_at);

        $winningNumber = (int) $elevenAmResult->twod;

        $this->assertTrue(
            $wonBet->betNumbers()->where('number', $winningNumber)->exists(),
            'WON bet must contain the winning 2D number.'
        );

        $this->assertFalse(
            $lostBet->betNumbers()->where('number', $winningNumber)->exists(),
            'LOST bet must not contain the winning 2D number.'
        );

        $refundedBet = Bet::query()->where('status', BetStatus::REFUNDED->value)->first();

        $this->assertNotNull($refundedBet);
        $this->assertSame(BetPayoutStatus::REFUNDED->value, $refundedBet->payout_status->value);

        $this->assertSame(5, Bet::query()->count());
        $this->assertSame(2, TwoDResult::query()->count());
        $this->assertSame(8, (int) \DB::table('bet_numbers')->count());

        $this->seed(BetSettlementTestingSeeder::class);

        $this->assertSame(5, Bet::query()->count());
        $this->assertSame(2, TwoDResult::query()->count());
        $this->assertSame(8, (int) \DB::table('bet_numbers')->count());
    }
}
