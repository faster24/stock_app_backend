<?php

namespace Tests\Feature\Betting;

use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Models\TwoDResult;
use App\Models\User;
use App\Services\Bet\BetSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BetSettlementIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_settle_two_d_result_is_a_no_op_when_history_id_was_already_processed(): void
    {
        $user = User::factory()->normalUser()->create();

        $bet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::OPEN,
            'target_opentime' => '11:00:00',
            'stock_date' => '2026-03-19',
        ]);
        $bet->betNumbers()->createMany([
            ['number' => 12],
        ]);

        $result = TwoDResult::query()->create([
            'history_id' => 'history-idempotent',
            'stock_date' => '2026-03-19',
            'stock_datetime' => '2026-03-19 11:00:00',
            'open_time' => '11:00:00',
            'twod' => '12',
            'payload' => [],
        ]);

        $service = app(BetSettlementService::class);

        $firstSummary = $service->settleTwoDResult($result);
        $bet->refresh();
        $firstSettledAt = $bet->settled_at?->toDateTimeString();

        $secondSummary = $service->settleTwoDResult($result);
        $bet->refresh();

        $this->assertSame([
            'settled' => 1,
            'won' => 1,
            'lost' => 0,
            'skipped' => 0,
        ], $firstSummary);

        $this->assertSame([
            'settled' => 0,
            'won' => 0,
            'lost' => 0,
            'skipped' => 0,
        ], $secondSummary);

        $this->assertSame(BetResultStatus::WON, $bet->bet_result_status);
        $this->assertSame('history-idempotent', $bet->settled_result_history_id);
        $this->assertSame($firstSettledAt, $bet->settled_at?->toDateTimeString());
        $this->assertDatabaseCount('bet_settlement_runs', 1);
    }
}
