<?php

namespace App\Enums;

enum BetResultStatus: string
{
    case WON = 'WON';
    case LOST = 'LOST';
    case VOID = 'VOID';
}
