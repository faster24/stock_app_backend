<?php

namespace App\Services\Bet;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Models\Bet;
use App\Services\Service;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class BetPayoutService extends Service
{
    private const MESSAGE_ALREADY_PAID = 'Bet is already paid out.';

    private const MESSAGE_ALREADY_REFUNDED = 'Bet refund is already recorded.';

    private const MESSAGE_NOT_ELIGIBLE = 'Bet is not eligible for payout.';

    private const MESSAGE_NOT_ELIGIBLE_FOR_REFUND = 'Bet is not eligible for refund.';

    public function payoutWinningBet(
        string $betId,
        int $adminUserId,
        UploadedFile $payoutProofImage,
        ?string $reference = null,
        ?string $note = null
    ): ?Bet {
        return $this->executeSettlement(
            $betId,
            $adminUserId,
            $payoutProofImage,
            $reference,
            $note,
            BetPayoutStatus::PAID_OUT,
            self::MESSAGE_NOT_ELIGIBLE,
            static fn (Bet $bet): bool =>
                $bet->status === BetStatus::ACCEPTED
                && $bet->bet_result_status === BetResultStatus::WON
        );
    }

    public function refundBet(
        string $betId,
        int $adminUserId,
        UploadedFile $payoutProofImage,
        ?string $reference = null,
        ?string $note = null
    ): ?Bet {
        return $this->executeSettlement(
            $betId,
            $adminUserId,
            $payoutProofImage,
            $reference,
            $note,
            BetPayoutStatus::REFUNDED,
            self::MESSAGE_NOT_ELIGIBLE_FOR_REFUND,
            static fn (Bet $bet): bool => in_array(
                $bet->status,
                [BetStatus::PENDING, BetStatus::ACCEPTED, BetStatus::REJECTED],
                true
            )
        );
    }

    /**
     * @param callable(Bet): bool $eligibilityCheck
     */
    private function executeSettlement(
        string $betId,
        int $adminUserId,
        UploadedFile $proofImage,
        ?string $reference,
        ?string $note,
        BetPayoutStatus $targetStatus,
        string $eligibilityMessage,
        callable $eligibilityCheck
    ): ?Bet {
        $policy = app(BetStatusTransitionPolicy::class);
        $settledAt = Carbon::now();

        try {
            return DB::transaction(function () use (
                $betId,
                $adminUserId,
                $proofImage,
                $reference,
                $note,
                $targetStatus,
                $eligibilityMessage,
                $eligibilityCheck,
                $policy,
                $settledAt
            ): ?Bet {
                $bet = Bet::query()
                    ->with(['betNumbers', 'media', 'user.wallet'])
                    ->whereKey($betId)
                    ->lockForUpdate()
                    ->first();

                if ($bet === null) {
                    return null;
                }

                if ($bet->payout_status === BetPayoutStatus::PAID_OUT) {
                    throw new DomainException(self::MESSAGE_ALREADY_PAID);
                }

                if ($bet->payout_status === BetPayoutStatus::REFUNDED) {
                    throw new DomainException(self::MESSAGE_ALREADY_REFUNDED);
                }

                if (! $eligibilityCheck($bet)) {
                    throw new DomainException($eligibilityMessage);
                }

                $policy->assertPayoutTransitionAllowed(
                    BetPayoutStatus::PENDING,
                    $targetStatus,
                    $bet->bet_result_status
                );

                $auditColumns = $this->availablePayoutAuditColumns();
                $updatePayload = [
                    'payout_status' => $targetStatus,
                ];

                if ($auditColumns['paid_out_at']) {
                    $updatePayload['paid_out_at'] = $settledAt;
                }

                if ($auditColumns['paid_out_by_user_id']) {
                    $updatePayload['paid_out_by_user_id'] = $adminUserId;
                }

                if ($auditColumns['payout_reference']) {
                    $updatePayload['payout_reference'] = $reference;
                }

                if ($auditColumns['payout_note']) {
                    $updatePayload['payout_note'] = $note;
                }

                $bet->update($updatePayload);

                try {
                    $bet->addMedia($proofImage)->toMediaCollection('payout_proof');
                } catch (Throwable) {
                    throw ValidationException::withMessages([
                        'payout_proof_image' => ['The payout proof image could not be saved.'],
                    ]);
                }

                return $bet->fresh(['betNumbers', 'media', 'user.wallet']);
            });
        } catch (DomainException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw $throwable;
        }
    }

    private function availablePayoutAuditColumns(): array
    {
        static $cached = null;

        if (is_array($cached)) {
            return $cached;
        }

        $table = 'bets';
        $cached = [
            'paid_out_at' => Schema::hasColumn($table, 'paid_out_at'),
            'paid_out_by_user_id' => Schema::hasColumn($table, 'paid_out_by_user_id'),
            'payout_reference' => Schema::hasColumn($table, 'payout_reference'),
            'payout_note' => Schema::hasColumn($table, 'payout_note'),
        ];

        return $cached;
    }
}
