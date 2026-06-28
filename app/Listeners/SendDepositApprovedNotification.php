<?php

namespace App\Listeners;

use App\Events\DepositApprovedEvent;
use App\Jobs\SendNotificationJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendDepositApprovedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(DepositApprovedEvent $event): void
    {
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
        );
    }
}
