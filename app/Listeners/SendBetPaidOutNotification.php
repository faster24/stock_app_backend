<?php

namespace App\Listeners;

use App\Events\BetPaidOutEvent;
use App\Jobs\SendNotificationJob;
use App\Listeners\Concerns\NeverFailsTheCaller;

class SendBetPaidOutNotification
{
    use NeverFailsTheCaller;

    public function handle(BetPaidOutEvent $event): void
    {
        $this->withoutFailing('bet_paid_out', function () use ($event) {
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
            )->afterCommit();
        });
    }
}
