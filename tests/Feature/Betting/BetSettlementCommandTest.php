<?php

namespace Tests\Feature\Betting;

use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Models\TwoDResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BetSettlementCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_settlement_command_fails_when_history_id_is_missing(): void
    {
        $this->artisan('bets:settle-2d missing-history')
            ->expectsOutput('TwoDResult not found for history_id [missing-history].')
            ->assertExitCode(1);
    }

    public function test_settlement_command_reports_success_and_duplicate_run_no_op_counts(): void
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
            ['number' => 1, 'amount' => 1000],
        ]);

        TwoDResult::query()->create([
            'history_id' => 'history-command',
            'stock_date' => '2026-03-19',
            'stock_datetime' => '2026-03-19 11:00:00',
            'open_time' => '11:00:00',
            'twod' => '01',
            'payload' => [],
        ]);

        $this->artisan('bets:settle-2d history-command')
            ->expectsOutput('History ID: history-command')
            ->expectsOutput('Settled: 1')
            ->expectsOutput('Won: 1')
            ->expectsOutput('Lost: 0')
            ->expectsOutput('Skipped: 0')
            ->assertExitCode(0);

        $this->artisan('bets:settle-2d history-command')
            ->expectsOutput('History ID: history-command')
            ->expectsOutput('Settled: 0')
            ->expectsOutput('Won: 0')
            ->expectsOutput('Lost: 0')
            ->expectsOutput('Skipped: 0')
            ->assertExitCode(0);
    }
}
