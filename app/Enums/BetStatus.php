<?php

namespace App\Enums;

enum BetStatus: string
{
    case PENDING = 'PENDING';
    case ACCEPTED = 'ACCEPTED';
    case REJECTED = 'REJECTED';
    case REFUNDED = 'REFUNDED';
}
