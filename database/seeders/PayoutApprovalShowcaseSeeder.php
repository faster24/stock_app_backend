<?php

namespace Database\Seeders;

use App\Enums\BankName;
use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Models\Bet;
use App\Models\ThreeDResult;
use App\Models\TwoDResult;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Bet\BetPayoutService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Guard;

/**
 * Standalone demo data for the winning-bet payout-approval gate.
 *
 * Seeds every reachable (status, bet_result_status, payout_status) combination
 * so the admin dashboard and player-facing WebClient can be manually exercised
 * against each case — in particular the new WON+PENDING "awaiting admin
 * approval" state introduced alongside BetPayoutService.
 *
 * Idempotent: safe to re-run. Reuses the standard demo accounts
 * (test@example.com / admin@lotto.com) so it can be run standalone without
 * DatabaseSeeder.
 *
 *   php artisan db:seed --class=Database\\Seeders\\PayoutApprovalShowcaseSeeder
 */
class PayoutApprovalShowcaseSeeder extends Seeder
{
    private const PLAYER_EMAIL = 'test@example.com';

    private const ADMIN_EMAIL = 'admin@lotto.com';

    public function run(): void
    {
        $player = $this->ensurePlayer();
        $admin = $this->ensureAdmin();

        $today = Carbon::now()->toDateString();
        $tomorrow = Carbon::now()->addDay()->toDateString();

        $closedNoon = $this->seedTwoDResult('showcase-2d-noon', $today, '12:01:00', '47');
        $closedEvening = $this->seedTwoDResult('showcase-2d-evening', $today, '16:30:00', '09');
        $this->seedThreeDResult($today, '256');

        // 1. PENDING review — awaiting admin accept/reject.
        $this->upsertBet($player->id, '20000000-0000-0000-0000-000000000001', BetType::TWO_D, $today, '16:30:00',
            BetStatus::PENDING, BetResultStatus::OPEN, BetPayoutStatus::PENDING,
            [['number' => 23, 'amount' => 1000], ['number' => 67, 'amount' => 1000]]);

        // 2. ACCEPTED, draw hasn't happened yet.
        $this->upsertBet($player->id, '20000000-0000-0000-0000-000000000002', BetType::TWO_D, $tomorrow, '12:01:00',
            BetStatus::ACCEPTED, BetResultStatus::OPEN, BetPayoutStatus::PENDING,
            [['number' => 45, 'amount' => 2000]]);

        // 3. THE key scenario: settled winner awaiting admin payout approval.
        $this->upsertBet($player->id, '20000000-0000-0000-0000-000000000003', BetType::TWO_D, $today, '12:01:00',
            BetStatus::ACCEPTED, BetResultStatus::WON, BetPayoutStatus::PENDING,
            [['number' => 47, 'amount' => 1000, 'odd' => 85, 'potential_winning' => 85_000]],
            settledAt: $closedNoon->stock_datetime, settledResultHistoryId: $closedNoon->history_id);

        // 4. Settled winner whose payout HAS been approved (real approve() call —
        //    produces a genuine wallet credit + ledger row + audit stamp). Once
        //    approved, leave it untouched on re-runs: re-upserting would reset
        //    payout_status to PENDING and approve() would credit the wallet again.
        $paidBetSlip = '20000000-0000-0000-0000-000000000004';
        $existingPaidBet = Bet::query()->where('bet_slip', $paidBetSlip)->first();

        if ($existingPaidBet !== null && $existingPaidBet->payout_status === BetPayoutStatus::PAID_OUT) {
            $paidBet = $existingPaidBet;
        } else {
            $paidBet = $this->upsertBet($player->id, $paidBetSlip, BetType::TWO_D, $today, '16:30:00',
                BetStatus::ACCEPTED, BetResultStatus::WON, BetPayoutStatus::PENDING,
                [['number' => 9, 'amount' => 1000, 'odd' => 85, 'potential_winning' => 85_000]],
                settledAt: $closedEvening->stock_datetime, settledResultHistoryId: $closedEvening->history_id);

            $paidBet = app(BetPayoutService::class)->approve(
                $paidBet->refresh(),
                (string) $admin->id,
                'SHOWCASE-REF-001',
                'Seeded demo payout for showcase.',
            );
        }

        // 5. Settled loser — payout stays PENDING forever (losers are never paid).
        $this->upsertBet($player->id, '20000000-0000-0000-0000-000000000005', BetType::TWO_D, $today, '12:01:00',
            BetStatus::ACCEPTED, BetResultStatus::LOST, BetPayoutStatus::PENDING,
            [['number' => 12, 'amount' => 1000], ['number' => 33, 'amount' => 1000]],
            settledAt: $closedNoon->stock_datetime, settledResultHistoryId: $closedNoon->history_id);

        // 6. Admin rejected during review.
        $this->upsertBet($player->id, '20000000-0000-0000-0000-000000000006', BetType::TWO_D, $today, '16:30:00',
            BetStatus::REJECTED, BetResultStatus::INVALID, BetPayoutStatus::PENDING,
            [['number' => 78, 'amount' => 1000]]);

        // 7. Accepted then refunded (e.g. duplicate/erroneous bet).
        $this->upsertBet($player->id, '20000000-0000-0000-0000-000000000007', BetType::TWO_D, $today, '16:30:00',
            BetStatus::REFUNDED, BetResultStatus::INVALID, BetPayoutStatus::REFUNDED,
            [['number' => 88, 'amount' => 1000]]);

        // 8. 3D winner awaiting approval — the gate applies to 3D bets too.
        $this->upsertBet($player->id, '20000000-0000-0000-0000-000000000008', BetType::THREE_D, $today, '16:30:00',
            BetStatus::ACCEPTED, BetResultStatus::WON, BetPayoutStatus::PENDING,
            [['number' => 256, 'amount' => 1000, 'odd' => 500, 'potential_winning' => 500_000]],
            settledAt: Carbon::parse($today)->toDateTimeString(), settledResultHistoryId: '3d-result-'.$today);

        // 9. 3D loser.
        $this->upsertBet($player->id, '20000000-0000-0000-0000-000000000009', BetType::THREE_D, $today, '16:30:00',
            BetStatus::ACCEPTED, BetResultStatus::LOST, BetPayoutStatus::PENDING,
            [['number' => 111, 'amount' => 1000]],
            settledAt: Carbon::parse($today)->toDateTimeString(), settledResultHistoryId: '3d-result-'.$today);

        $this->command?->info('PayoutApprovalShowcaseSeeder: 9 bet scenarios seeded for '.self::PLAYER_EMAIL.'.');
    }

    private function ensurePlayer(): User
    {
        $player = User::query()->updateOrCreate([
            'email' => self::PLAYER_EMAIL,
        ], [
            'username' => 'testuser',
            'password' => Hash::make('password'),
        ]);

        // firstOrCreate, NOT updateOrCreate: the balance is mutated for real by
        // BetPayoutService::approve() below, and this seeder must be re-runnable
        // without clobbering that credit back to the starting balance.
        Wallet::query()->firstOrCreate([
            'user_id' => $player->id,
        ], [
            'balance' => 100_000,
            'currency' => Currency::MMK->value,
            'currency_locked_at' => now(),
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'Test User',
            'account_number' => '1111111111',
        ]);

        return $player;
    }

    private function ensureAdmin(): User
    {
        $admin = User::query()->updateOrCreate([
            'email' => self::ADMIN_EMAIL,
        ], [
            'username' => 'admin',
            'password' => Hash::make('password'),
        ]);

        app('Spatie\\Permission\\PermissionRegistrar')->forgetCachedPermissions();
        call_user_func(['Spatie\\Permission\\Models\\Role', 'findOrCreate'], 'admin', Guard::getDefaultName($admin));
        $admin->syncRoles(['admin']);

        Wallet::query()->firstOrCreate([
            'user_id' => $admin->id,
        ], [
            'currency' => Currency::MMK->value,
            'currency_locked_at' => now(),
        ]);

        return $admin;
    }

    private function seedTwoDResult(string $historyId, string $stockDate, string $openTime, string $twod): TwoDResult
    {
        return TwoDResult::query()->updateOrCreate([
            'history_id' => $historyId,
        ], [
            'stock_date' => $stockDate,
            'stock_datetime' => $stockDate.' '.$openTime,
            'open_time' => $openTime,
            'twod' => $twod,
            'payload' => ['seed' => 'PayoutApprovalShowcaseSeeder'],
        ]);
    }

    private function seedThreeDResult(string $stockDate, string $threed): ThreeDResult
    {
        return ThreeDResult::query()->updateOrCreate([
            'stock_date' => $stockDate,
        ], [
            'threed' => $threed,
        ]);
    }

    /**
     * @param  array<int, array{number: int, amount: int, odd?: int|float, potential_winning?: int|float}>  $numbers
     */
    private function upsertBet(
        string $userId,
        string $betSlip,
        BetType $betType,
        string $stockDate,
        string $openTime,
        BetStatus $status,
        BetResultStatus $resultStatus,
        BetPayoutStatus $payoutStatus,
        array $numbers,
        ?string $settledAt = null,
        ?string $settledResultHistoryId = null,
    ): Bet {
        $totalAmount = array_sum(array_column($numbers, 'amount'));

        $bet = Bet::query()->updateOrCreate([
            'bet_slip' => $betSlip,
        ], [
            'user_id' => $userId,
            'bet_type' => $betType->value,
            'currency' => Currency::MMK->value,
            'target_opentime' => $openTime,
            'stock_date' => $stockDate,
            'total_amount' => $totalAmount,
            'status' => $status->value,
            'bet_result_status' => $resultStatus->value,
            'payout_status' => $payoutStatus->value,
            'placed_at' => $stockDate.' 09:30:00',
            'settled_at' => $settledAt,
            'settled_result_history_id' => $settledResultHistoryId,
        ]);

        $bet->betNumbers()->delete();

        foreach ($numbers as $entry) {
            $bet->betNumbers()->create([
                'number' => $entry['number'],
                'amount' => $entry['amount'],
                'odd' => $entry['odd'] ?? null,
                'potential_winning' => $entry['potential_winning'] ?? 0,
            ]);
        }

        return $bet->fresh();
    }
}
