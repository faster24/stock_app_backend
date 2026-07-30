<?php

namespace App\Listeners;

use App\Events\SettlementRevertedEvent;
use App\Jobs\SendNotificationJob;
use App\Listeners\Concerns\NeverFailsTheCaller;

class SendSettlementRevertedNotification
{
    use NeverFailsTheCaller;

    public function handle(SettlementRevertedEvent $event): void
    {
        $this->withoutFailing('settlement_reverted', function () use ($event) {
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
            )->afterCommit();
        });
    }
}
