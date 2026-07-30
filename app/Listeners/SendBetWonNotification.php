<?php

namespace App\Listeners;

use App\Events\BetWonEvent;
use App\Jobs\SendNotificationJob;
use App\Listeners\Concerns\NeverFailsTheCaller;

class SendBetWonNotification
{
    use NeverFailsTheCaller;

    public function handle(BetWonEvent $event): void
    {
        $this->withoutFailing('bet_won', function () use ($event) {
            $bet = $event->bet;

            SendNotificationJob::dispatch(
                $bet->user,
                'Congratulations! Bet Won!',
                "Your bet #{$bet->id} has won! Your payout is being reviewed and will be credited once approved.",
                [
                    'type' => 'bet_won',
                    'bet_id' => $bet->id,
                ],
                'bet_won'
            )->afterCommit();
        });
    }
}
