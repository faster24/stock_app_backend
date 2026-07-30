<?php

namespace App\Events;

use App\Models\Withdrawal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WithdrawalRejectedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Withdrawal $withdrawal) {}
}
