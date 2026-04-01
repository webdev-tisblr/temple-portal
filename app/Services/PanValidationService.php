<?php

declare(strict_types=1);

namespace App\Services;

class PanValidationService
{
    public function validate(string $pan): bool
    {
        return (bool) preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', strtoupper($pan));
    }

    public function isPanRequired(float $amount): bool
    {
        return $amount >= 2000;
    }
}
