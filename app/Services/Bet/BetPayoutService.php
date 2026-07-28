<?php

namespace App\Services\Bet;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Events\BetPaidOutEvent;
use App\Models\Bet;
use App\Services\Service;
use App\Services\Wallet\WalletMutator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin-triggered payout of winning bets.
 *
 * Settlement marks winners WON but no longer credits them; the money waits here
 * until an admin approves. Approval is atomic (wallet credit + PAID_OUT in one
 * transaction) and idempotent (a second approval is rejected by the transition
 * policy).
 */
class BetPayoutService extends Service
{
    public function __construct(
        private WalletMutator $walletMutator,
        private BetStatusTransitionPolicy $transitionPolicy,
    ) {}

    /**
     * Approve and pay out a single winning bet.
     *
     * @throws DomainException when the bet is not an accepted, WON, still-pending payout.
     */
    public function approve(Bet $bet, string $adminUserId, ?string $reference = null, ?string $note = null): Bet
    {
        return DB::transaction(function () use ($bet, $adminUserId, $reference, $note): Bet {
            $locked = Bet::query()->whereKey($bet->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== BetStatus::ACCEPTED) {
                throw new DomainException('Only accepted bets can be paid out.');
            }

            // Explicit idempotency guard: the transition policy treats
            // PAID_OUT -> PAID_OUT as a no-op, so a second approval would slip
            // through. Reject anything not currently awaiting payout.
            if ($locked->payout_status !== BetPayoutStatus::PENDING) {
                throw new DomainException('Bet payout is not pending approval.');
            }

            // Enforces PENDING -> PAID_OUT and "PAID_OUT requires WON".
            $this->transitionPolicy->assertPayoutTransitionAllowed(
                $locked->payout_status,
                BetPayoutStatus::PAID_OUT,
                $locked->bet_result_status,
            );

            $payout = $this->resolvePayoutAmount($locked);

            if ($payout <= 0) {
                throw new DomainException('Winning bet has no positive payout amount.');
            }

            $paidAt = Carbon::now();

            $this->walletMutator->mutate(
                userId: $locked->user_id,
                type: WalletTransactionType::BET_WIN,
                direction: WalletTransactionDirection::CREDIT,
                amount: $payout,
                reference: $locked,
                createdByUserId: $adminUserId,
                // Deterministic ledger note keyed on the settlement run so a later
                // SettlementReversal can identify and claw back this payout. The
                // admin's free-text note is stored on the bet (payout_note), not here.
                note: "Settlement: {$locked->settled_result_history_id}",
            );

            $locked->forceFill([
                'payout_status' => BetPayoutStatus::PAID_OUT->value,
                'paid_out_at' => $paidAt,
                'paid_out_by_user_id' => $adminUserId,
                'payout_reference' => $reference,
                'payout_note' => $note,
            ])->save();

            BetPaidOutEvent::dispatch($locked);

            return $locked->refresh();
        });
    }

    /**
     * Approve every WON + payout-pending bet for one draw (date + slot).
     * Each bet is paid in its own transaction so one failure does not block the rest.
     *
     * @return array{approved: int, skipped: int, total_paid: int}
     */
    public function approveBulk(string $stockDate, string $targetOpentime, string $adminUserId, ?string $note = null): array
    {
        $summary = ['approved' => 0, 'skipped' => 0, 'total_paid' => 0];

        Bet::query()
            ->where('status', BetStatus::ACCEPTED->value)
            ->where('bet_result_status', BetResultStatus::WON->value)
            ->where('payout_status', BetPayoutStatus::PENDING->value)
            ->whereDate('stock_date', $stockDate)
            ->where('target_opentime', $targetOpentime)
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$summary, $adminUserId, $note): void {
                foreach ($rows as $row) {
                    $bet = Bet::find($row->id);

                    if ($bet === null) {
                        $summary['skipped']++;

                        continue;
                    }

                    try {
                        $paid = $this->approve($bet, $adminUserId, null, $note);
                        $summary['approved']++;
                        $summary['total_paid'] += $this->resolvePayoutAmount($paid);
                    } catch (DomainException) {
                        $summary['skipped']++;
                    }
                }
            });

        return $summary;
    }

    /** Sum of potential_winning across the bet's numbers that match the drawn number. */
    private function resolvePayoutAmount(Bet $bet): int
    {
        $winningNumber = $bet->winning_number;

        if ($winningNumber === null) {
            return 0;
        }

        return (int) DB::table('bet_numbers')
            ->where('bet_id', $bet->getKey())
            ->where('number', $winningNumber)
            ->sum('potential_winning');
    }
}
