<?php

namespace Tests\Feature\Betting;

use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Models\BetNumber;
use App\Models\BetResult;
use App\Models\Result;
use App\Models\Round;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BettingSchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_betting_schema_supports_valid_graph_traversal(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->for($user)->create();
        $round = Round::factory()->settled()->create();
        $bet = Bet::factory()->forUserAndRound($user, $round)->create([
            'bet_type' => BetType::STRAIGHT,
            'status' => BetStatus::WON,
        ]);
        $betNumber = BetNumber::factory()->forBetWithNumber($bet, 12)->create();
        $result = Result::factory()->forRoundWithWinningNumber($round, 12)->create();
        $betResult = BetResult::factory()->forBetAndResult($bet, $result)->create([
            'status' => BetResultStatus::WON,
            'payout_amount' => 80_000,
        ]);

        $loadedUser = User::query()
            ->with(['wallet', 'bets.round', 'bets.betNumbers', 'bets.betResults.result.round'])
            ->findOrFail($user->id);

        $this->assertSame($wallet->id, $loadedUser->wallet->id);
        $this->assertCount(1, $loadedUser->bets);
        $this->assertSame($round->id, $loadedUser->bets->first()->round->id);
        $this->assertSame($betNumber->id, $loadedUser->bets->first()->betNumbers->first()->id);
        $this->assertSame($betResult->id, $loadedUser->bets->first()->betResults->first()->id);
        $this->assertSame($result->id, $loadedUser->bets->first()->betResults->first()->result->id);
        $this->assertSame($round->id, $loadedUser->bets->first()->betResults->first()->result->round->id);
    }

    public function test_betting_schema_rejects_duplicate_wallet_for_same_user(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->for($user)->create();

        $this->expectException(QueryException::class);

        Wallet::factory()->for($user)->create();
    }

    public function test_betting_schema_rejects_duplicate_result_for_same_round(): void
    {
        $round = Round::factory()->create();
        Result::factory()->for($round)->create();

        $this->expectException(QueryException::class);

        Result::factory()->for($round)->create();
    }

    public function test_betting_schema_rejects_invalid_foreign_keys(): void
    {
        $this->expectException(QueryException::class);

        Bet::query()->create([
            'user_id' => 999_999,
            'round_id' => 999_999,
            'bet_type' => BetType::STRAIGHT,
            'amount' => 1_000,
            'status' => BetStatus::PENDING,
            'placed_at' => now(),
        ]);
    }

    public function test_betting_schema_rejects_invalid_enum_values(): void
    {
        $user = User::factory()->create();
        $round = Round::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('bets')->insert([
            'user_id' => $user->id,
            'round_id' => $round->id,
            'bet_type' => 'INVALID',
            'amount' => 1_000,
            'status' => BetStatus::PENDING->value,
            'placed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
