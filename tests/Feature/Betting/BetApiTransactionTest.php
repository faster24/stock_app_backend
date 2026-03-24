<?php

namespace Tests\Feature\Betting;

use App\Models\Bet;
use App\Models\BetNumber;
use App\Models\User;
use App\Services\Bet\BetService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BetApiTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_for_user_rolls_back_parent_and_children_when_child_insert_fails(): void
    {
        $user = User::factory()->normalUser()->create();
        $service = app(BetService::class);

        $betsBefore = Bet::query()->count();
        $betNumbersBefore = BetNumber::query()->count();
        $insertFailed = false;

        try {
            $service->createForUser($user->id, [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '2D',
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
            'bet_type' => '2D',
            'amount' => 1000,
        ]);
    }

    public function test_create_for_user_rejects_out_of_range_numbers_by_bet_type_at_service_layer(): void
    {
        $user = User::factory()->normalUser()->create();
        $service = app(BetService::class);

        $betsBefore = Bet::query()->count();
        $betNumbersBefore = BetNumber::query()->count();

        $invalidPayloads = [
            [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '2D',
                'amount' => 1000,
                'bet_numbers' => [0],
            ],
            [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '3D',
                'amount' => 1000,
                'bet_numbers' => [0],
            ],
        ];

        foreach ($invalidPayloads as $payload) {
            try {
                $service->createForUser($user->id, $payload);

                $this->fail('Expected out-of-range bet number to fail service validation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('bet_numbers.0', $exception->errors());
            }
        }

        $this->assertSame($betsBefore, Bet::query()->count());
        $this->assertSame($betNumbersBefore, BetNumber::query()->count());
    }
}
