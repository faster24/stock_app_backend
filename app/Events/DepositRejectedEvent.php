<?php

namespace App\Events;

use App\Models\Deposit;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DepositRejectedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Deposit $deposit) {}
}
