<?php

namespace App\Listeners;

use App\Events\SettlementRevertedEvent;
use App\Jobs\SendNotificationJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendSettlementRevertedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(SettlementRevertedEvent $event): void
    {
        $bet = $event->bet;

        SendNotificationJob::dispatch(
            $bet->user,
            'Result Correction',
            "A draw result was corrected. Bet #{$bet->id} was re-opened and {$event->debitedAmount} was reversed from your wallet. It will be settled again with the corrected result.",
            [
                'type' => 'settlement_reverted',
                'bet_id' => $bet->id,
            ],
            'settlement_reverted'
        );
    }
}
