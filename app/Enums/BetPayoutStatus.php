<?php

namespace App\Enums;

enum BetPayoutStatus: string
{
    case PENDING = 'PENDING';
    case PAID_OUT = 'PAID_OUT';
}
