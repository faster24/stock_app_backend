<?php

namespace App\Enums;

enum BetResultStatus: string
{
    case OPEN = 'OPEN';
    case WON = 'WON';
    case LOST = 'LOST';
    case VOID = 'VOID';
}
