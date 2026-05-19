<?php

namespace App\Enums;

enum WithdrawalStatus: string
{
    case PENDING   = 'PENDING';
    case COMPLETED = 'COMPLETED';
    case REJECTED  = 'REJECTED';
}
