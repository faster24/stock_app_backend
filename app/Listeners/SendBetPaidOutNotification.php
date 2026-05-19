<?php

namespace App\Listeners;

use App\Events\BetPaidOutEvent;
use App\Jobs\SendNotificationJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendBetPaidOutNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(BetPaidOutEvent $event): void
    {
        $bet = $event->bet;

        SendNotificationJob::dispatch(
            $bet->user,
            'Payout Processed!',
            "Your winning bet #{$bet->id} has been paid out. Check your account.",
            [
                'type' => 'bet_paid_out',
                'bet_id' => $bet->id,
            ],
            'bet_paid_out'
        );
    }
}
