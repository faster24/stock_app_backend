<?php

namespace Tests\Feature\Betting;

use App\Models\Bet;
use App\Models\BetNumber;
use App\Models\Round;
use App\Models\User;
use App\Services\Bet\BetService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BetApiTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_for_user_rolls_back_parent_and_children_when_child_insert_fails(): void
    {
        $user = User::factory()->normalUser()->create();
        $round = Round::factory()->create();
        $service = app(BetService::class);

        $betsBefore = Bet::query()->count();
        $betNumbersBefore = BetNumber::query()->count();
        $insertFailed = false;

        try {
            $service->createForUser($user->id, [
                'round_id' => $round->id,
                'bet_type' => 'STRAIGHT',
                'amount' => 1000,
                'bet_numbers' => [55, 55],
            ]);

            $this->fail('Expected duplicate bet numbers to fail child insert.');
        } catch (QueryException) {
            $insertFailed = true;
        }

        $this->assertTrue($insertFailed);

        $this->assertSame($betsBefore, Bet::query()->count());
        $this->assertSame($betNumbersBefore, BetNumber::query()->count());
        $this->assertDatabaseCount('bets', $betsBefore);
        $this->assertDatabaseCount('bet_numbers', $betNumbersBefore);
        $this->assertDatabaseMissing('bets', [
            'user_id' => $user->id,
            'round_id' => $round->id,
            'bet_type' => 'STRAIGHT',
            'amount' => 1000,
        ]);
    }
}
