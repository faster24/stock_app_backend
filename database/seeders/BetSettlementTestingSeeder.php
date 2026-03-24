<?php

namespace Database\Seeders;

use App\Enums\BetResultStatus;
use App\Enums\BetPayoutStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Models\TwoDResult;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class BetSettlementTestingSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->updateOrCreate([
            'email' => 'bet-tester@example.com',
        ], [
            'username' => 'bettester',
            'password' => Hash::make('password'),
        ]);

        $this->seedTwoDResults();
        $this->seedBetFlowSnapshot($user->id);
    }

    private function seedTwoDResults(): void
    {
        TwoDResult::query()->updateOrCreate([
            'history_id' => 'settlement-test-2026-03-19-11-00',
        ], [
            'stock_date' => '2026-03-19',
            'stock_datetime' => '2026-03-19 11:00:00',
            'open_time' => '11:00:00',
            'twod' => '12',
            'payload' => [
                'seed' => 'BetSettlementTestingSeeder',
                'label' => '11:00 winning number is 12 (full-flow snapshot)',
            ],
        ]);

        TwoDResult::query()->updateOrCreate([
            'history_id' => 'settlement-test-2026-03-19-12-01',
        ], [
            'stock_date' => '2026-03-19',
            'stock_datetime' => '2026-03-19 12:01:00',
            'open_time' => '12:01:00',
            'twod' => '78',
            'payload' => [
                'seed' => 'BetSettlementTestingSeeder',
                'label' => '12:01 winning number is 78 (full-flow snapshot)',
            ],
        ]);
    }

    private function seedBetFlowSnapshot(int $userId): void
    {
        $this->upsertBet(
            userId: $userId,
            betSlip: '10000000-0000-0000-0000-000000000007',
            stockDate: '2026-03-19',
            openTime: '11:00:00',
            status: BetStatus::PENDING,
            resultStatus: BetResultStatus::OPEN,
            payoutStatus: BetPayoutStatus::PENDING,
            numbers: [12, 90],
            amount: 1000
        );

        $this->upsertBet(
            userId: $userId,
            betSlip: '10000000-0000-0000-0000-000000000008',
            stockDate: '2026-03-19',
            openTime: '11:00:00',
            status: BetStatus::ACCEPTED,
            resultStatus: BetResultStatus::WON,
            payoutStatus: BetPayoutStatus::PENDING,
            settledAt: '2026-03-19 11:01:00',
            settledResultHistoryId: 'settlement-test-2026-03-19-11-00',
            numbers: [12, 45],
            amount: 1000
        );

        $this->upsertBet(
            userId: $userId,
            betSlip: '10000000-0000-0000-0000-000000000009',
            stockDate: '2026-03-19',
            openTime: '11:00:00',
            status: BetStatus::ACCEPTED,
            resultStatus: BetResultStatus::LOST,
            payoutStatus: BetPayoutStatus::PENDING,
            settledAt: '2026-03-19 11:01:00',
            settledResultHistoryId: 'settlement-test-2026-03-19-11-00',
            numbers: [34, 56],
            amount: 1000
        );

        $this->upsertBet(
            userId: $userId,
            betSlip: '10000000-0000-0000-0000-000000000010',
            stockDate: '2026-03-19',
            openTime: '12:01:00',
            status: BetStatus::REJECTED,
            resultStatus: BetResultStatus::INVALID,
            payoutStatus: BetPayoutStatus::PENDING,
            numbers: [78],
            amount: 1000
        );

        $this->upsertBet(
            userId: $userId,
            betSlip: '10000000-0000-0000-0000-000000000011',
            stockDate: '2026-03-19',
            openTime: '12:01:00',
            status: BetStatus::REFUNDED,
            resultStatus: BetResultStatus::INVALID,
            payoutStatus: BetPayoutStatus::REFUNDED,
            numbers: [88],
            amount: 1000
        );
    }

    private function upsertBet(
        int $userId,
        string $betSlip,
        string $stockDate,
        string $openTime,
        BetStatus $status,
        BetResultStatus $resultStatus,
        BetPayoutStatus $payoutStatus,
        array $numbers,
        int $amount,
        ?string $settledAt = null,
        ?string $settledResultHistoryId = null,
        BetType $betType = BetType::TWO_D
    ): void {
        $uniqueNumbers = array_values(array_unique($numbers));

        $bet = Bet::query()->updateOrCreate([
            'bet_slip' => $betSlip,
        ], [
            'user_id' => $userId,
            'bet_type' => $betType->value,
            'target_opentime' => $openTime,
            'stock_date' => $stockDate,
            'amount' => $amount,
            'total_amount' => array_sum(array_fill(0, count($uniqueNumbers), $amount)),
            'status' => $status->value,
            'bet_result_status' => $resultStatus->value,
            'payout_status' => $payoutStatus->value,
            'placed_at' => $stockDate.' 09:30:00',
            'settled_at' => $settledAt,
            'settled_result_history_id' => $settledResultHistoryId,
        ]);

        $bet->betNumbers()->delete();

        foreach ($uniqueNumbers as $number) {
            $bet->betNumbers()->create([
                'number' => $number,
                'amount' => $amount,
            ]);
        }
    }
}
