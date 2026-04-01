<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatus: string
{
    case CREATED = 'created';
    case AUTHORIZED = 'authorized';
    case CAPTURED = 'captured';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';
}
