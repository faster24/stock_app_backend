<?php

namespace App\Enums;

enum DepositStatus: string
{
    case PENDING  = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
}
