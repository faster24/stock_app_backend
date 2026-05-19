<?php

namespace App\Enums;

enum WalletTransactionDirection: string
{
    case CREDIT = 'CREDIT';
    case DEBIT  = 'DEBIT';
}
