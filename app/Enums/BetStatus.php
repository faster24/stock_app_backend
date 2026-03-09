<?php

namespace App\Enums;

enum BetStatus: string
{
    case PENDING = 'PENDING';
    case WON = 'WON';
    case LOST = 'LOST';
    case CANCELLED = 'CANCELLED';
}
