<?php

namespace App\Listeners;

use App\Events\WithdrawalCompletedEvent;
use App\Jobs\SendNotificationJob;
use App\Listeners\Concerns\NeverFailsTheCaller;

class SendWithdrawalCompletedNotification
{
    use NeverFailsTheCaller;

    public function handle(WithdrawalCompletedEvent $event): void
    {
        $this->withoutFailing('withdrawal_completed', function () use ($event) {
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
            )->afterCommit();
        });
    }
}
