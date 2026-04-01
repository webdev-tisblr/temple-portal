<?php

declare(strict_types=1);

namespace App\Enums;

enum DonationType: string
{
    case GENERAL = 'general';
    case SEVA = 'seva';
    case ANNADAN = 'annadan';
    case CONSTRUCTION = 'construction';
    case FESTIVAL = 'festival';
    case CAMPAIGN = 'campaign';
    case OTHER = 'other';
}
