<?php

namespace App\Listeners;

use App\Events\WithdrawalRejectedEvent;
use App\Jobs\SendNotificationJob;
use App\Listeners\Concerns\NeverFailsTheCaller;

class SendWithdrawalRejectedNotification
{
    use NeverFailsTheCaller;

    public function handle(WithdrawalRejectedEvent $event): void
    {
        $this->withoutFailing('withdrawal_rejected', function () use ($event) {
            $withdrawal = $event->withdrawal;

            SendNotificationJob::dispatch(
                $withdrawal->user,
                'Withdrawal Rejected',
                "Your withdrawal of {$withdrawal->amount} {$withdrawal->currency->value} was rejected and the amount has been returned to your wallet. Reason: {$withdrawal->rejection_reason}",
                [
                    'type' => 'withdrawal_rejected',
                    'withdrawal_id' => $withdrawal->id,
                ],
                'withdrawal_rejected'
            )->afterCommit();
        });
    }
}
