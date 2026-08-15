<?php

namespace App\Services\Admin;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\DepositStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Services\Service;

/**
 * The three queues an admin has to work through, as one snapshot. Drives both
 * the dashboard badges and the aggregated admin push.
 */
class PendingRequestCountsService extends Service
{
    /**
     * @return array{bets: int, deposits: int, withdrawals: int, total: int}
     */
    public function counts(): array
    {
        // Bets placed from the app are ACCEPTED on creation — nothing waits on
        // admin review. The real bet queue is settled winners the admin still
        // has to credit, since settlement deliberately never moves money.
        $bets = Bet::query()
            ->where('status', BetStatus::ACCEPTED->value)
            ->where('bet_result_status', BetResultStatus::WON->value)
            ->where('payout_status', BetPayoutStatus::PENDING->value)
            ->count();

        $deposits = Deposit::query()
            ->where('status', DepositStatus::PENDING->value)
            ->count();

        $withdrawals = Withdrawal::query()
            ->where('status', WithdrawalStatus::PENDING->value)
            ->count();

        return [
            'bets' => $bets,
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'total' => $bets + $deposits + $withdrawals,
        ];
    }
}
