<?php

namespace App\Listeners;

use App\Events\DepositRejectedEvent;
use App\Jobs\SendNotificationJob;
use App\Listeners\Concerns\NeverFailsTheCaller;

class SendDepositRejectedNotification
{
    use NeverFailsTheCaller;

    public function handle(DepositRejectedEvent $event): void
    {
        $this->withoutFailing('deposit_rejected', function () use ($event) {
            $deposit = $event->deposit;

            SendNotificationJob::dispatch(
                $deposit->user,
                'Deposit Rejected',
                "Your deposit request of {$deposit->claimed_amount} {$deposit->currency->value} was rejected. Reason: {$deposit->rejection_reason}",
                [
                    'type' => 'deposit_rejected',
                    'deposit_id' => $deposit->id,
                ],
                'deposit_rejected'
            )->afterCommit();
        });
    }
}
