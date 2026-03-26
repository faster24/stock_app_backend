<?php

namespace Tests\Feature\Betting;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Models\Bet;
use App\Models\BetNumber;
use App\Models\OddSetting;
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
        $this->seedOddSetting(BetType::TWO_D, Currency::MMK, OddSettingUserType::USER, '80.00');

        $user = User::factory()->normalUser()->create();
        $service = app(BetService::class);

        $betsBefore = Bet::query()->count();
        $betNumbersBefore = BetNumber::query()->count();
        $insertFailed = false;

        try {
            $service->createForUser($user->id, [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '2D',
                'currency' => 'MMK',
                'bet_numbers' => [
                    ['number' => 55, 'amount' => 1000],
                    ['number' => 55, 'amount' => 1000],
                ],
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
            'currency' => 'MMK',
        ]);
    }

    public function test_create_for_user_rejects_out_of_range_numbers_by_bet_type_at_service_layer(): void
    {
        $this->seedOddSetting(BetType::TWO_D, Currency::MMK, OddSettingUserType::USER, '80.00');
        $this->seedOddSetting(BetType::THREE_D, Currency::MMK, OddSettingUserType::USER, '80.00');

        $user = User::factory()->normalUser()->create();
        $service = app(BetService::class);

        $betsBefore = Bet::query()->count();
        $betNumbersBefore = BetNumber::query()->count();

        $invalidPayloads = [
            [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '2D',
                'currency' => 'MMK',
                'bet_numbers' => [['number' => 0, 'amount' => 1000]],
            ],
            [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '3D',
                'currency' => 'MMK',
                'bet_numbers' => [['number' => 0, 'amount' => 1000]],
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

    private function seedOddSetting(BetType $betType, Currency $currency, OddSettingUserType $userType, string $odd): void
    {
        OddSetting::query()->updateOrCreate([
            'bet_type' => $betType,
            'currency' => $currency,
            'user_type' => $userType,
        ], [
            'odd' => $odd,
            'is_active' => true,
        ]);
    }
}
