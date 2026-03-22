<?php

namespace App\Services\Bet;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use DomainException;

class BetStatusTransitionPolicy
{
    private const REVIEW_PENDING = 'PENDING';

    private const REVIEW_ACCEPTED = 'ACCEPTED';

    private const REVIEW_REJECTED = 'REJECTED';

    private const REVIEW_REFUNDED = 'REFUNDED';

    private const MESSAGE_INVALID_REVIEW_STATUS = 'Invalid review status for transition.';

    private const MESSAGE_ILLEGAL_REVIEW_TRANSITION = 'Illegal review status transition.';

    private const MESSAGE_ILLEGAL_RESULT_TRANSITION = 'Illegal result status transition.';

    private const MESSAGE_ILLEGAL_PAYOUT_TRANSITION = 'Illegal payout status transition.';

    private const MESSAGE_PAYOUT_REQUIRES_WON_RESULT = 'Payout status PAID_OUT requires result status WON.';

    public function assertReviewTransitionAllowed(BetStatus|string $fromStatus, BetStatus|string $toStatus): void
    {
        $from = $this->resolveStatusValue($fromStatus);
        $to = $this->resolveStatusValue($toStatus);

        if (! in_array($from, $this->reviewStatuses(), true) || ! in_array($to, $this->reviewStatuses(), true)) {
            throw new DomainException(self::MESSAGE_INVALID_REVIEW_STATUS);
        }

        if ($from === $to) {
            return;
        }

        $allowedTransitions = [
            self::REVIEW_PENDING => [self::REVIEW_ACCEPTED, self::REVIEW_REJECTED],
            self::REVIEW_ACCEPTED => [self::REVIEW_REFUNDED],
            self::REVIEW_REJECTED => [],
            self::REVIEW_REFUNDED => [],
        ];

        if (! in_array($to, $allowedTransitions[$from], true)) {
            throw new DomainException(self::MESSAGE_ILLEGAL_REVIEW_TRANSITION);
        }
    }

    public function assertResultTransitionAllowed(BetResultStatus $fromStatus, BetResultStatus $toStatus): void
    {
        if ($fromStatus === $toStatus) {
            return;
        }

        $allowedTransitions = [
            BetResultStatus::OPEN->value => [
                BetResultStatus::WON->value,
                BetResultStatus::LOST->value,
                BetResultStatus::VOID->value,
            ],
            BetResultStatus::WON->value => [],
            BetResultStatus::LOST->value => [],
            BetResultStatus::VOID->value => [],
        ];

        if (! in_array($toStatus->value, $allowedTransitions[$fromStatus->value], true)) {
            throw new DomainException(self::MESSAGE_ILLEGAL_RESULT_TRANSITION);
        }
    }

    public function assertPayoutTransitionAllowed(
        BetPayoutStatus $fromStatus,
        BetPayoutStatus $toStatus,
        BetResultStatus $resultStatus
    ): void {
        if ($toStatus === BetPayoutStatus::PAID_OUT && $resultStatus !== BetResultStatus::WON) {
            throw new DomainException(self::MESSAGE_PAYOUT_REQUIRES_WON_RESULT);
        }

        if ($fromStatus === $toStatus) {
            return;
        }

        $allowedTransitions = [
            BetPayoutStatus::PENDING->value => [
                BetPayoutStatus::PAID_OUT->value,
                BetPayoutStatus::REFUNDED->value,
            ],
            BetPayoutStatus::PAID_OUT->value => [],
            BetPayoutStatus::REFUNDED->value => [],
        ];

        if (! in_array($toStatus->value, $allowedTransitions[$fromStatus->value], true)) {
            throw new DomainException(self::MESSAGE_ILLEGAL_PAYOUT_TRANSITION);
        }
    }

    private function reviewStatuses(): array
    {
        return [
            self::REVIEW_PENDING,
            self::REVIEW_ACCEPTED,
            self::REVIEW_REJECTED,
            self::REVIEW_REFUNDED,
        ];
    }

    private function resolveStatusValue(BetStatus|string $status): string
    {
        if ($status instanceof BetStatus) {
            return $status->value;
        }

        return $status;
    }
}
