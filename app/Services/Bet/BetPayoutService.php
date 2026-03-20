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
use Illuminate\Validation\ValidationException;
use Throwable;

class BetPayoutService extends Service
{
    private const MESSAGE_ALREADY_PAID = 'Bet is already paid out.';

    private const MESSAGE_NOT_ELIGIBLE = 'Bet is not eligible for payout.';

    public function payoutWinningBet(
        string $betId,
        int $adminUserId,
        UploadedFile $payoutProofImage,
        ?string $reference = null,
        ?string $note = null
    ): ?Bet {
        $policy = app(BetStatusTransitionPolicy::class);
        $paidOutAt = Carbon::now();

        try {
            return DB::transaction(function () use (
                $betId,
                $adminUserId,
                $payoutProofImage,
                $reference,
                $note,
                $policy,
                $paidOutAt
            ): ?Bet {
                $bet = Bet::query()
                    ->with(['betNumbers', 'media'])
                    ->whereKey($betId)
                    ->lockForUpdate()
                    ->first();

                if ($bet === null) {
                    return null;
                }

                if ($bet->payout_status === BetPayoutStatus::PAID_OUT) {
                    throw new DomainException(self::MESSAGE_ALREADY_PAID);
                }

                $isEligible = $bet->status === BetStatus::ACCEPTED
                    && $bet->bet_result_status === BetResultStatus::WON
                    && $bet->payout_status === BetPayoutStatus::PENDING;

                if (! $isEligible) {
                    throw new DomainException(self::MESSAGE_NOT_ELIGIBLE);
                }

                $policy->assertPayoutTransitionAllowed(
                    BetPayoutStatus::PENDING,
                    BetPayoutStatus::PAID_OUT,
                    BetResultStatus::WON
                );

                $bet->update([
                    'payout_status' => BetPayoutStatus::PAID_OUT,
                    'paid_out_at' => $paidOutAt,
                    'paid_out_by_user_id' => $adminUserId,
                    'payout_reference' => $reference,
                    'payout_note' => $note,
                ]);

                try {
                    $bet->addMedia($payoutProofImage)->toMediaCollection('payout_proof');
                } catch (Throwable) {
                    throw ValidationException::withMessages([
                        'payout_proof_image' => ['The payout proof image could not be saved.'],
                    ]);
                }

                return $bet->fresh(['betNumbers', 'media']);
            });
        } catch (DomainException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw $throwable;
        }
    }
}

