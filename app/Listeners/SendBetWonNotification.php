<?php

namespace App\Listeners;

use App\Events\BetWonEvent;
use App\Jobs\SendNotificationJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendBetWonNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(BetWonEvent $event): void
    {
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
        );
    }
}
