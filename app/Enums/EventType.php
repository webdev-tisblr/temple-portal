<?php

declare(strict_types=1);

namespace App\Enums;

enum EventType: string
{
    case FESTIVAL = 'festival';
    case SPECIAL_PUJA = 'special_puja';
    case SATSANG = 'satsang';
    case CULTURAL = 'cultural';
    case OTHER = 'other';
}
