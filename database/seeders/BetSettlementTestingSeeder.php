<?php

namespace Database\Seeders;

use App\Enums\BetResultStatus;
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
        $user = User::query()->firstOrCreate([
            'email' => 'bet-tester@example.com',
        ], [
            'name' => 'Bet Tester',
            'username' => 'bettester',
            'password' => Hash::make('password'),
        ]);

        $this->seedElevenAmScenario($user->id);
        $this->seedNoonScenario($user->id);
    }

    private function seedElevenAmScenario(int $userId): void
    {
        $stockDate = '2026-03-19';
        $openTime = '11:00:00';

        TwoDResult::query()->updateOrCreate([
            'history_id' => 'settlement-test-2026-03-19-11-00',
        ], [
            'stock_date' => $stockDate,
            'stock_datetime' => '2026-03-19 11:00:00',
            'open_time' => $openTime,
            'twod' => '12',
            'payload' => [
                'seed' => 'BetSettlementTestingSeeder',
                'label' => '11:00 winning number is 12',
            ],
        ]);

        $this->upsertBet(
            userId: $userId,
            betSlip: '10000000-0000-0000-0000-000000000001',
            stockDate: $stockDate,
            openTime: $openTime,
            status: BetStatus::ACCEPTED,
            resultStatus: BetResultStatus::OPEN,
            numbers: [12, 18],
            amount: 1000
        );

        $this->upsertBet(
            userId: $userId,
            betSlip: '10000000-0000-0000-0000-000000000002',
            stockDate: $stockDate,
            openTime: $openTime,
            status: BetStatus::ACCEPTED,
            resultStatus: BetResultStatus::OPEN,
            numbers: [44],
            amount: 1000
        );

        $this->upsertBet(
            userId: $userId,
            betSlip: '10000000-0000-0000-0000-000000000003',
            stockDate: $stockDate,
            openTime: $openTime,
            status: BetStatus::PENDING,
            resultStatus: BetResultStatus::OPEN,
            numbers: [12],
            amount: 1000
        );

        $this->upsertBet(
            userId: $userId,
            betSlip: '10000000-0000-0000-0000-000000000004',
            stockDate: $stockDate,
            openTime: $openTime,
            status: BetStatus::ACCEPTED,
            resultStatus: BetResultStatus::WON,
            numbers: [12],
            amount: 1000
        );

        $this->upsertBet(
            userId: $userId,
            betSlip: '10000000-0000-0000-0000-000000000005',
            stockDate: $stockDate,
            openTime: '12:01:00',
            status: BetStatus::ACCEPTED,
            resultStatus: BetResultStatus::OPEN,
            numbers: [12],
            amount: 1000
        );

        $this->upsertBet(
            userId: $userId,
            betSlip: '10000000-0000-0000-0000-000000000006',
            stockDate: $stockDate,
            openTime: $openTime,
            status: BetStatus::ACCEPTED,
            resultStatus: BetResultStatus::OPEN,
            numbers: [123],
            amount: 1000,
            betType: BetType::THREE_D
        );
    }

    private function seedNoonScenario(int $userId): void
    {
        $stockDate = '2026-03-19';
        $openTime = '12:01:00';

        TwoDResult::query()->updateOrCreate([
            'history_id' => 'settlement-test-2026-03-19-12-01',
        ], [
            'stock_date' => $stockDate,
            'stock_datetime' => '2026-03-19 12:01:00',
            'open_time' => $openTime,
            'twod' => '78',
            'payload' => [
                'seed' => 'BetSettlementTestingSeeder',
                'label' => '12:01 winning number is 78',
            ],
        ]);

        $this->upsertBet(
            userId: $userId,
            betSlip: '10000000-0000-0000-0000-000000000007',
            stockDate: $stockDate,
            openTime: $openTime,
            status: BetStatus::ACCEPTED,
            resultStatus: BetResultStatus::OPEN,
            numbers: [78, 11],
            amount: 2000
        );

        $this->upsertBet(
            userId: $userId,
            betSlip: '10000000-0000-0000-0000-000000000008',
            stockDate: $stockDate,
            openTime: $openTime,
            status: BetStatus::ACCEPTED,
            resultStatus: BetResultStatus::OPEN,
            numbers: [55],
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
        array $numbers,
        int $amount,
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
            'total_amount' => $amount * count($uniqueNumbers),
            'status' => $status->value,
            'bet_result_status' => $resultStatus->value,
            'payout_status' => 'PENDING',
            'placed_at' => $stockDate.' 09:30:00',
            'settled_at' => null,
            'settled_result_history_id' => null,
        ]);

        $bet->betNumbers()->delete();

        foreach ($uniqueNumbers as $number) {
            $bet->betNumbers()->create([
                'number' => $number,
            ]);
        }
    }
}
