<?php

namespace App\Enums;

enum RoundStatus: string
{
    case PENDING = 'PENDING';
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
    case SETTLED = 'SETTLED';
    case CANCELLED = 'CANCELLED';
}
