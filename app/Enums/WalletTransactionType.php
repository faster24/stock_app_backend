<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case DEPOSIT = 'DEPOSIT';
    case BET_PLACE = 'BET_PLACE';
    case BET_REFUND = 'BET_REFUND';
    case BET_WIN = 'BET_WIN';
    case BET_WIN_REVERSAL = 'BET_WIN_REVERSAL';
    case WITHDRAWAL = 'WITHDRAWAL';
    case WITHDRAWAL_REFUND = 'WITHDRAWAL_REFUND';
    case ADJUSTMENT = 'ADJUSTMENT';
}
