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

class BetSettlementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_settle_two_d_result_updates_only_eligible_bets_and_preserves_ineligible_ones(): void
    {
        $user = User::factory()->normalUser()->create();

        $winningBet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::OPEN,
            'target_opentime' => '11:00:00',
            'stock_date' => '2026-03-19',
        ]);
        $winningBet->betNumbers()->createMany([
            ['number' => 12],
            ['number' => 13],
        ]);

        $losingBet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::OPEN,
            'target_opentime' => '11:00:00',
            'stock_date' => '2026-03-19',
        ]);
        $losingBet->betNumbers()->createMany([
            ['number' => 44],
        ]);

        $pendingBet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'status' => BetStatus::PENDING,
            'bet_result_status' => BetResultStatus::OPEN,
            'target_opentime' => '11:00:00',
            'stock_date' => '2026-03-19',
        ]);
        $pendingBet->betNumbers()->createMany([
            ['number' => 12],
        ]);

        $alreadySettledBet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'target_opentime' => '11:00:00',
            'stock_date' => '2026-03-19',
        ]);
        $alreadySettledBet->betNumbers()->createMany([
            ['number' => 12],
        ]);

        $differentTypeBet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::THREE_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::OPEN,
            'target_opentime' => '11:00:00',
            'stock_date' => '2026-03-19',
        ]);

        $result = TwoDResult::query()->create([
            'history_id' => 'history-11-00',
            'stock_date' => '2026-03-19',
            'stock_datetime' => '2026-03-19 11:00:00',
            'open_time' => '11:00:00',
            'twod' => '12',
            'payload' => [],
        ]);

        $summary = app(BetSettlementService::class)->settleTwoDResult($result, 1);

        $this->assertSame([
            'settled' => 2,
            'won' => 1,
            'lost' => 1,
            'skipped' => 2,
        ], $summary);

        $this->assertDatabaseHas('bets', [
            'id' => $winningBet->id,
            'bet_result_status' => BetResultStatus::WON->value,
            'settled_result_history_id' => 'history-11-00',
        ]);

        $this->assertDatabaseHas('bets', [
            'id' => $losingBet->id,
            'bet_result_status' => BetResultStatus::LOST->value,
            'settled_result_history_id' => 'history-11-00',
        ]);

        $this->assertDatabaseHas('bets', [
            'id' => $pendingBet->id,
            'bet_result_status' => BetResultStatus::OPEN->value,
            'settled_result_history_id' => null,
        ]);

        $this->assertDatabaseHas('bets', [
            'id' => $alreadySettledBet->id,
            'bet_result_status' => BetResultStatus::WON->value,
            'settled_result_history_id' => null,
        ]);

        $this->assertDatabaseHas('bets', [
            'id' => $differentTypeBet->id,
            'bet_result_status' => BetResultStatus::OPEN->value,
            'settled_result_history_id' => null,
        ]);

        $this->assertDatabaseHas('bet_settlement_runs', [
            'history_id' => 'history-11-00',
            'two_d_result_id' => $result->id,
        ]);
    }

    public function test_settle_two_d_result_accepts_leading_zero_winning_number(): void
    {
        $user = User::factory()->normalUser()->create();

        $winningBet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::OPEN,
            'target_opentime' => '11:00:00',
            'stock_date' => '2026-03-19',
        ]);
        $winningBet->betNumbers()->createMany([
            ['number' => 1, 'amount' => 1000],
        ]);

        $losingBet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::OPEN,
            'target_opentime' => '11:00:00',
            'stock_date' => '2026-03-19',
        ]);
        $losingBet->betNumbers()->createMany([
            ['number' => 22, 'amount' => 1000],
        ]);

        $result = TwoDResult::query()->create([
            'history_id' => 'history-leading-zero-2d',
            'stock_date' => '2026-03-19',
            'stock_datetime' => '2026-03-19 11:00:00',
            'open_time' => '11:00:00',
            'twod' => '01',
            'payload' => [],
        ]);

        $summary = app(BetSettlementService::class)->settleTwoDResult($result, 100);

        $this->assertSame(2, $summary['settled']);
        $this->assertSame(1, $summary['won']);
        $this->assertSame(1, $summary['lost']);

        $this->assertDatabaseHas('bets', [
            'id' => $winningBet->id,
            'bet_result_status' => BetResultStatus::WON->value,
            'settled_result_history_id' => 'history-leading-zero-2d',
        ]);

        $this->assertDatabaseHas('bets', [
            'id' => $losingBet->id,
            'bet_result_status' => BetResultStatus::LOST->value,
            'settled_result_history_id' => 'history-leading-zero-2d',
        ]);
    }
}
