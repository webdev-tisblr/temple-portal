<?php

declare(strict_types=1);

namespace App\Enums;

enum SevaCategory: string
{
    case SHRINGAR = 'shringar';
    case VASTRA = 'vastra';
    case ANNADAN = 'annadan';
    case PUJA = 'puja';
    case SPECIAL = 'special';
    case OTHER = 'other';
}
