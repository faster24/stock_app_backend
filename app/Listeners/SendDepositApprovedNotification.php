<?php

namespace App\Listeners;

use App\Events\DepositApprovedEvent;
use App\Jobs\SendNotificationJob;
use App\Listeners\Concerns\NeverFailsTheCaller;

class SendDepositApprovedNotification
{
    use NeverFailsTheCaller;

    public function handle(DepositApprovedEvent $event): void
    {
        $this->withoutFailing('deposit_approved', function () use ($event) {
            $deposit = $event->deposit;

            SendNotificationJob::dispatch(
                $deposit->user,
                'Deposit Approved!',
                "Your deposit of {$deposit->approved_amount} {$deposit->currency->value} has been approved and credited to your wallet.",
                [
                    'type' => 'deposit_approved',
                    'deposit_id' => $deposit->id,
                ],
                'deposit_approved'
            )->afterCommit();
        });
    }
}
