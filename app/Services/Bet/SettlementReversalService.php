<?php

namespace App\Services\Bet;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Events\SettlementRevertedEvent;
use App\Models\Bet;
use App\Models\SettlementReversal;
use App\Models\SettlementReversalItem;
use App\Models\Wallet;
use App\Services\Service;
use App\Services\Wallet\WalletMutator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reverts a completed settlement run so the period can be re-settled with a
 * corrected winning number.
 *
 * Reversal amounts are computed from the wallet ledger (BET_WIN credits noted
 * "Settlement: {historyId}" net of prior BET_WIN_REVERSAL debits) — never from
 * bet_numbers.potential_winning, which temp odds may have rewritten.
 *
 * Clawback policy: debit whatever balance is available; any shortfall is
 * recorded on the reversal item for manual follow-up. The revert always
 * completes. The bet_settlement_runs row is deleted only at the very end, so
 * a scheduler re-fire during the revert still sees a completed run and NOOPs.
 */
class SettlementReversalService extends Service
{
    public function __construct(private WalletMutator $walletMutator) {}

    public function previewRevert(string $historyId): array
    {
        $run = $this->findCompletedRun($historyId);

        if ($run === null) {
            throw new DomainException('No completed settlement run found for this history_id.');
        }

        $resultExists = $this->resultExists($run);
        $winningNumber = $this->runWinningNumber($run);

        $items = [];
        $totals = [
            'bets' => 0,
            'winners' => 0,
            'paid_total' => 0,
            'debitable_total' => 0,
            'projected_shortfall_total' => 0,
            'users_with_shortfall' => 0,
        ];

        $bets = Bet::query()
            ->with('user:id,username')
            ->where('settled_result_history_id', $historyId)
            ->orderBy('id')
            ->get(['id', 'user_id', 'bet_result_status', 'total_amount']);

        $shortfallUsers = [];

        foreach ($bets as $bet) {
            $paid = $this->outstandingPaidAmount($bet->id, $historyId);
            $balance = (int) (Wallet::query()->where('user_id', $bet->user_id)->value('balance') ?? 0);
            $debitable = min($paid, $balance);
            $shortfall = $paid - $debitable;

            $totals['bets']++;
            if ($bet->bet_result_status === BetResultStatus::WON) {
                $totals['winners']++;
            }
            $totals['paid_total'] += $paid;
            $totals['debitable_total'] += $debitable;
            $totals['projected_shortfall_total'] += $shortfall;
            if ($shortfall > 0) {
                $shortfallUsers[$bet->user_id] = true;
            }

            $items[] = [
                'bet_id' => $bet->id,
                'user_id' => $bet->user_id,
                'username' => $bet->user?->username,
                'previous_result_status' => $bet->bet_result_status->value,
                'paid' => $paid,
                'balance' => $balance,
                'debitable' => $debitable,
                'shortfall' => $shortfall,
            ];
        }

        $totals['users_with_shortfall'] = count($shortfallUsers);

        return [
            'run' => [
                'history_id' => $run->history_id,
                'bet_type' => $run->bet_type,
                'stock_date' => $run->stock_date,
                'open_time' => $run->open_time,
                'winning_number' => $winningNumber,
                'settled_at' => $run->settled_at,
                'summary' => $run->summary !== null ? json_decode($run->summary, true) : null,
                'result_exists' => $resultExists,
            ],
            'totals' => $totals,
            'items' => $items,
        ];
    }

    public function revert(
        string $historyId,
        string $revertedByUserId,
        string $reason,
        int $chunkSize = 500
    ): SettlementReversal {
        $reversal = DB::transaction(function () use ($historyId, $revertedByUserId, $reason) {
            $run = $this->findCompletedRun($historyId, lock: true);

            if ($run === null) {
                throw new DomainException('No completed settlement run found for this history_id.');
            }

            $existing = SettlementReversal::query()
                ->where('history_id', $historyId)
                ->where('status', SettlementReversal::STATUS_REVERTING)
                ->first();

            if ($existing !== null) {
                return $existing; // Resume an interrupted revert.
            }

            return SettlementReversal::create([
                'history_id' => $historyId,
                'bet_type' => (string) $run->bet_type,
                'two_d_result_id' => $run->two_d_result_id,
                'three_d_result_id' => $run->three_d_result_id,
                'reverted_winning_number' => $this->runWinningNumber($run),
                'reverted_by_user_id' => $revertedByUserId,
                'reason' => $reason,
                'status' => SettlementReversal::STATUS_REVERTING,
                'run_settled_at' => $run->settled_at,
                'original_summary' => $run->summary !== null ? json_decode($run->summary, true) : null,
            ]);
        });

        $resolvedChunkSize = max(1, min(2000, $chunkSize));
        $notifications = [];

        Bet::query()
            ->where('settled_result_history_id', $historyId)
            ->select('id')
            ->orderBy('id')
            ->chunkById($resolvedChunkSize, function ($bets) use ($reversal, $historyId, $revertedByUserId, &$notifications): void {
                foreach ($bets as $betRow) {
                    $outcome = $this->revertSingleBet($betRow->id, $reversal, $historyId, $revertedByUserId);

                    if ($outcome !== null && $outcome['debited'] > 0) {
                        $notifications[] = $outcome;
                    }
                }
            });

        foreach ($notifications as $outcome) {
            $bet = Bet::query()->with('user')->find($outcome['bet_id']);

            if ($bet !== null) {
                SettlementRevertedEvent::dispatch($bet, $outcome['debited'], $outcome['shortfall']);
            }
        }

        return DB::transaction(function () use ($reversal, $historyId) {
            $totals = SettlementReversalItem::query()
                ->where('settlement_reversal_id', $reversal->id)
                ->selectRaw(
                    'COUNT(*) as bets_reverted,
                    SUM(CASE WHEN previous_result_status = ? THEN 1 ELSE 0 END) as winners_reversed,
                    SUM(CASE WHEN previous_result_status = ? THEN 1 ELSE 0 END) as losers_reset,
                    COALESCE(SUM(debited_amount), 0) as total_debited,
                    COALESCE(SUM(shortfall_amount), 0) as total_shortfall',
                    [BetResultStatus::WON->value, BetResultStatus::LOST->value]
                )
                ->first();

            $reversal->update([
                'status' => SettlementReversal::STATUS_COMPLETED,
                'completed_at' => Carbon::now(),
                'total_debited' => (int) ($totals->total_debited ?? 0),
                'total_shortfall' => (int) ($totals->total_shortfall ?? 0),
                'summary' => [
                    'bets_reverted' => (int) ($totals->bets_reverted ?? 0),
                    'winners_reversed' => (int) ($totals->winners_reversed ?? 0),
                    'losers_reset' => (int) ($totals->losers_reset ?? 0),
                    'total_debited' => (int) ($totals->total_debited ?? 0),
                    'total_shortfall' => (int) ($totals->total_shortfall ?? 0),
                ],
            ]);

            // Deleting the run row last re-enables settlement for this history_id.
            DB::table('bet_settlement_runs')->where('history_id', $historyId)->delete();

            return $reversal->refresh();
        });
    }

    /**
     * @return array{bet_id: string, debited: int, shortfall: int}|null null when the bet was skipped
     */
    private function revertSingleBet(
        string $betId,
        SettlementReversal $reversal,
        string $historyId,
        string $revertedByUserId
    ): ?array {
        return DB::transaction(function () use ($betId, $reversal, $historyId, $revertedByUserId): ?array {
            $bet = Bet::query()->lockForUpdate()->find($betId);

            if (
                $bet === null
                || $bet->settled_result_history_id !== $historyId
                || ! in_array($bet->bet_result_status, [BetResultStatus::WON, BetResultStatus::LOST], true)
            ) {
                return null;
            }

            $previousStatus = $bet->bet_result_status->value;
            $paid = $this->outstandingPaidAmount($bet->id, $historyId);
            $debited = 0;
            $shortfall = 0;
            $walletTransactionId = null;
            $note = null;

            if ($paid > 0) {
                $balance = (int) (Wallet::query()
                    ->where('user_id', $bet->user_id)
                    ->lockForUpdate()
                    ->value('balance') ?? 0);

                $debited = min($paid, $balance);
                $shortfall = $paid - $debited;

                if ($debited > 0) {
                    try {
                        $transaction = $this->walletMutator->mutate(
                            userId: $bet->user_id,
                            type: WalletTransactionType::BET_WIN_REVERSAL,
                            direction: WalletTransactionDirection::DEBIT,
                            amount: $debited,
                            reference: $bet,
                            createdByUserId: $revertedByUserId,
                            note: "Settlement reversal: {$historyId}",
                        );
                        $walletTransactionId = $transaction->id;
                    } catch (DomainException $exception) {
                        $note = 'Wallet debit failed: '.$exception->getMessage();
                        $shortfall = $paid;
                        $debited = 0;
                    }
                }
            }

            SettlementReversalItem::query()->updateOrCreate(
                [
                    'settlement_reversal_id' => $reversal->id,
                    'bet_id' => $bet->id,
                ],
                [
                    'user_id' => $bet->user_id,
                    'previous_result_status' => $previousStatus,
                    'paid_amount' => $paid,
                    'debited_amount' => $debited,
                    'shortfall_amount' => $shortfall,
                    'wallet_transaction_id' => $walletTransactionId,
                    'note' => $note,
                ]
            );

            $bet->update([
                'bet_result_status' => BetResultStatus::OPEN->value,
                'payout_status' => BetPayoutStatus::PENDING->value,
                'settled_at' => null,
                'settled_result_history_id' => null,
                'paid_out_at' => null,
                'paid_out_by_user_id' => null,
            ]);

            // Undo any temp-odd rewrite so a re-settle pays base odds.
            DB::table('bet_numbers')
                ->where('bet_id', $bet->id)
                ->whereNotNull('odd')
                ->update(['potential_winning' => DB::raw('amount * odd')]);

            return [
                'bet_id' => $bet->id,
                'debited' => $debited,
                'shortfall' => $shortfall,
            ];
        });
    }

    /**
     * Net amount still owed back for a bet under this settlement run:
     * BET_WIN credits minus BET_WIN_REVERSAL debits, clamped at zero.
     */
    private function outstandingPaidAmount(string $betId, string $historyId): int
    {
        $paid = (int) DB::table('wallet_transactions')
            ->where('type', WalletTransactionType::BET_WIN->value)
            ->where('reference_type', Bet::class)
            ->where('reference_id', $betId)
            ->where('note', "Settlement: {$historyId}")
            ->sum('amount');

        $reversed = (int) DB::table('wallet_transactions')
            ->where('type', WalletTransactionType::BET_WIN_REVERSAL->value)
            ->where('reference_type', Bet::class)
            ->where('reference_id', $betId)
            ->where('note', "Settlement reversal: {$historyId}")
            ->sum('amount');

        return max(0, $paid - $reversed);
    }

    private function findCompletedRun(string $historyId, bool $lock = false): ?object
    {
        $query = DB::table('bet_settlement_runs')
            ->leftJoin('two_d_results', 'two_d_results.id', '=', 'bet_settlement_runs.two_d_result_id')
            ->leftJoin('three_d_results', 'three_d_results.id', '=', 'bet_settlement_runs.three_d_result_id')
            ->where('bet_settlement_runs.history_id', $historyId)
            ->whereNotNull('bet_settlement_runs.settled_at')
            ->select([
                'bet_settlement_runs.history_id',
                'bet_settlement_runs.bet_type',
                'bet_settlement_runs.two_d_result_id',
                'bet_settlement_runs.three_d_result_id',
                'bet_settlement_runs.settled_at',
                'bet_settlement_runs.summary',
                DB::raw('COALESCE(two_d_results.stock_date, three_d_results.stock_date) as stock_date'),
                'two_d_results.open_time',
                'two_d_results.twod',
                'three_d_results.threed',
            ]);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function resultExists(object $run): bool
    {
        return $run->two_d_result_id !== null || $run->three_d_result_id !== null;
    }

    private function runWinningNumber(object $run): ?int
    {
        $raw = $run->twod ?? $run->threed;

        if ($raw === null || ! preg_match('/^\d+$/', (string) $raw)) {
            return null;
        }

        return (int) $raw;
    }
}
