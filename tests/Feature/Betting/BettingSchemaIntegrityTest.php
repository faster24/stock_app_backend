<?php

namespace Tests\Feature\Betting;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Models\BetNumber;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BettingSchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_betting_schema_supports_valid_graph_traversal(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->for($user)->create();
        $bet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'status' => BetStatus::ACCEPTED,
        ]);
        $betNumber = BetNumber::factory()->forBetWithNumber($bet, 12)->create();

        $loadedUser = User::query()
            ->with(['wallet', 'bets.betNumbers'])
            ->findOrFail($user->id);

        $this->assertSame($wallet->id, $loadedUser->wallet->id);
        $this->assertCount(1, $loadedUser->bets);
        $this->assertSame($betNumber->id, $loadedUser->bets->first()->betNumbers->first()->id);
    }

    public function test_betting_schema_rejects_duplicate_wallet_for_same_user(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->for($user)->create();

        $this->expectException(QueryException::class);

        Wallet::factory()->for($user)->create();
    }

    public function test_betting_schema_rejects_invalid_foreign_keys(): void
    {
        $this->expectException(QueryException::class);

        Bet::query()->create([
            'user_id' => 999_999,
            'bet_slip' => (string) Str::uuid(),
            'bet_type' => BetType::TWO_D,
            'amount' => 1_000,
            'status' => BetStatus::PENDING,
            'placed_at' => now(),
        ]);
    }

    public function test_betting_schema_rejects_invalid_enum_values(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('bets')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'bet_slip' => (string) Str::uuid(),
            'bet_type' => 'INVALID',
            'amount' => 1_000,
            'status' => BetStatus::PENDING->value,
            'placed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_betting_schema_rejects_invalid_settlement_enum_values(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('bets')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'bet_slip' => (string) Str::uuid(),
            'bet_type' => BetType::TWO_D->value,
            'amount' => 1_000,
            'status' => BetStatus::PENDING->value,
            'bet_result_status' => 'NOT_A_REAL_RESULT',
            'payout_status' => 'NOT_A_REAL_PAYOUT',
            'placed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_betting_schema_rejects_duplicate_bet_slip(): void
    {
        $user = User::factory()->create();
        $betSlip = (string) Str::uuid();

        Bet::factory()->for($user)->create([
            'bet_slip' => $betSlip,
        ]);

        $this->expectException(QueryException::class);

        Bet::factory()->for($user)->create([
            'bet_slip' => $betSlip,
        ]);
    }

    public function test_betting_schema_rejects_odd_setting_without_bet_amount(): void
    {
        $this->expectException(QueryException::class);

        DB::table('odd_settings')->insert([
            'bet_type' => BetType::TWO_D->value,
            'odd' => '80.00',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
