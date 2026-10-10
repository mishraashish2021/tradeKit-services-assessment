<?php

namespace App\Enums;

enum OrderType: string
{
    case LIMIT = 'LIMIT';
    case MARKET = 'MARKET';
}
