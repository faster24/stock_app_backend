<?php

namespace App\Events;

use App\Models\Bet;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BetPaidOutEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Bet $bet) {}
}
