<?php

namespace App\Services\User;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\DepositStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\ThreeDResult;
use App\Models\TwoDResult;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Collection;

/**
 * Read-only aggregates for a single customer, powering the admin user profile.
 *
 * Winning amounts are derived the same way BetPayoutService::resolvePayoutAmount
 * does it — the sum of potential_winning across bet numbers matching the drawn
 * number — so paid and not-yet-paid winnings are measured identically.
 */
class UserActivitySummaryService
{
    public function summarize(User $user): array
    {
        $userId = (string) $user->getKey();

        return [
            'wallet' => $this->walletSummary($user),
            'bets' => $this->betSummary($userId),
            'winnings' => $this->winningSummary($userId),
            'deposits' => $this->depositSummary($userId),
            'withdrawals' => $this->withdrawalSummary($userId),
        ];
    }

    private function walletSummary(User $user): array
    {
        $wallet = $user->wallet;

        return [
            'balance' => (int) ($wallet->balance ?? 0),
            'currency' => $wallet?->currency?->value,
        ];
    }

    private function betSummary(string $userId): array
    {
        $byStatus = Bet::query()
            ->where('user_id', $userId)
            ->selectRaw('status, COUNT(*) as bet_count, COALESCE(SUM(total_amount), 0) as staked')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row): string => (string) $row->getRawOriginal('status'));

        $byResult = Bet::query()
            ->where('user_id', $userId)
            ->whereIn('status', [BetStatus::ACCEPTED->value, BetStatus::PENDING->value])
            ->selectRaw('bet_result_status, COUNT(*) as bet_count')
            ->groupBy('bet_result_status')
            ->get()
            ->keyBy(fn ($row): string => (string) $row->getRawOriginal('bet_result_status'));

        $count = fn (Collection $rows, string $key): int => (int) ($rows->get($key)->bet_count ?? 0);

        // Rejected bets are refunded in full, so they do not count as money staked.
        $staked = $byStatus
            ->reject(fn ($row): bool => (string) $row->getRawOriginal('status') === BetStatus::REJECTED->value)
            ->sum(fn ($row): float => (float) $row->staked);

        return [
            'total' => (int) $byStatus->sum(fn ($row): int => (int) $row->bet_count),
            'total_staked' => (int) round($staked),
            'pending' => $count($byStatus, BetStatus::PENDING->value),
            'accepted' => $count($byStatus, BetStatus::ACCEPTED->value),
            'rejected' => $count($byStatus, BetStatus::REJECTED->value),
            'refunded' => $count($byStatus, BetStatus::REFUNDED->value),
            'open' => $count($byResult, BetResultStatus::OPEN->value),
            'won' => $count($byResult, BetResultStatus::WON->value),
            'lost' => $count($byResult, BetResultStatus::LOST->value),
        ];
    }

    private function winningSummary(string $userId): array
    {
        /** @var Collection<int, Bet> $wonBets */
        $wonBets = Bet::query()
            ->with('betNumbers')
            ->where('user_id', $userId)
            ->where('bet_result_status', BetResultStatus::WON->value)
            ->orderByDesc('settled_at')
            ->get();

        $drawnNumbers = $this->drawnNumbersFor($wonBets);

        $total = 0;
        $paidOut = 0;
        $pending = 0;
        $pendingCount = 0;

        foreach ($wonBets as $bet) {
            $drawn = $drawnNumbers[(string) $bet->settled_result_history_id] ?? null;

            if ($drawn === null) {
                continue;
            }

            $amount = (int) round(
                $bet->betNumbers
                    ->where('number', $drawn)
                    ->sum(fn ($number): float => (float) $number->potential_winning)
            );

            $total += $amount;

            if ($bet->payout_status === BetPayoutStatus::PAID_OUT) {
                $paidOut += $amount;

                continue;
            }

            if ($bet->payout_status === BetPayoutStatus::PENDING) {
                $pending += $amount;
                $pendingCount++;
            }
        }

        return [
            'won_bet_count' => $wonBets->count(),
            'total_won_amount' => $total,
            'paid_out_amount' => $paidOut,
            'pending_payout_amount' => $pending,
            'pending_payout_count' => $pendingCount,
            'last_won_at' => $wonBets->first()?->settled_at?->toIso8601String(),
        ];
    }

    /**
     * Resolve every won bet's drawn number in two queries instead of one per bet
     * (the Bet::winning_number accessor queries on each access).
     *
     * @param  Collection<int, Bet>  $bets
     * @return array<string, int>
     */
    private function drawnNumbersFor(Collection $bets): array
    {
        $twoDHistoryIds = $bets
            ->where('bet_type', BetType::TWO_D)
            ->pluck('settled_result_history_id')
            ->filter()
            ->unique()
            ->values();

        $threeDHistoryIds = $bets
            ->where('bet_type', BetType::THREE_D)
            ->pluck('settled_result_history_id')
            ->filter()
            ->unique()
            ->values();

        $numbers = [];

        if ($twoDHistoryIds->isNotEmpty()) {
            TwoDResult::query()
                ->whereIn('history_id', $twoDHistoryIds->all())
                ->get(['history_id', 'twod'])
                ->each(function ($result) use (&$numbers): void {
                    $numbers[(string) $result->history_id] = (int) $result->twod;
                });
        }

        foreach ($threeDHistoryIds as $historyId) {
            $date = str_replace('3d-result-', '', (string) $historyId);
            $threed = ThreeDResult::whereDate('stock_date', $date)->value('threed');

            if ($threed !== null) {
                $numbers[(string) $historyId] = (int) $threed;
            }
        }

        return $numbers;
    }

    private function depositSummary(string $userId): array
    {
        $rows = Deposit::query()
            ->where('user_id', $userId)
            ->selectRaw('status, COUNT(*) as deposit_count, COALESCE(SUM(approved_amount), 0) as approved_total')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row): string => (string) $row->getRawOriginal('status'));

        return [
            'total' => (int) $rows->sum(fn ($row): int => (int) $row->deposit_count),
            'approved_count' => (int) ($rows->get(DepositStatus::APPROVED->value)->deposit_count ?? 0),
            'approved_amount' => (int) ($rows->get(DepositStatus::APPROVED->value)->approved_total ?? 0),
            'pending_count' => (int) ($rows->get(DepositStatus::PENDING->value)->deposit_count ?? 0),
        ];
    }

    private function withdrawalSummary(string $userId): array
    {
        $rows = Withdrawal::query()
            ->where('user_id', $userId)
            ->selectRaw('status, COUNT(*) as withdrawal_count, COALESCE(SUM(amount), 0) as amount_total')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row): string => (string) $row->getRawOriginal('status'));

        return [
            'total' => (int) $rows->sum(fn ($row): int => (int) $row->withdrawal_count),
            'completed_count' => (int) ($rows->get(WithdrawalStatus::COMPLETED->value)->withdrawal_count ?? 0),
            'completed_amount' => (int) ($rows->get(WithdrawalStatus::COMPLETED->value)->amount_total ?? 0),
            'pending_count' => (int) ($rows->get(WithdrawalStatus::PENDING->value)->withdrawal_count ?? 0),
            'pending_amount' => (int) ($rows->get(WithdrawalStatus::PENDING->value)->amount_total ?? 0),
        ];
    }
}
