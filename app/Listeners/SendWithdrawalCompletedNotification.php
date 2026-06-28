<?php

namespace App\Listeners;

use App\Events\WithdrawalCompletedEvent;
use App\Jobs\SendNotificationJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendWithdrawalCompletedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(WithdrawalCompletedEvent $event): void
    {
        $withdrawal = $event->withdrawal;

        SendNotificationJob::dispatch(
            $withdrawal->user,
            'Withdrawal Completed!',
            "Your withdrawal of {$withdrawal->amount} {$withdrawal->currency->value} has been processed successfully.",
            [
                'type' => 'withdrawal_completed',
                'withdrawal_id' => $withdrawal->id,
            ],
            'withdrawal_completed'
        );
    }
}
