<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case FILLED = 'FILLED';
    case CANCELLED = 'CANCELLED';
    case REJECTED = 'REJECTED';
    case FAILED = 'FAILED';
}
