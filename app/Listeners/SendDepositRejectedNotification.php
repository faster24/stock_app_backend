<?php

namespace App\Listeners;

use App\Events\DepositRejectedEvent;
use App\Jobs\SendNotificationJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendDepositRejectedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(DepositRejectedEvent $event): void
    {
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
        );
    }
}
