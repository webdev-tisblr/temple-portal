<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical phone formats:
 *  - Indian numbers: bare 10 digits starting 6-9 (matches every existing
 *    temple_devotees row — phone is the unique login key, so legacy rows
 *    must never be rewritten).
 *  - Non-Indian numbers: full E.164 digits without '+', e.g. '447911123456'.
 */
class PhoneNumber
{
    /**
     * Normalize raw user input to the canonical stored form.
     * Returns null when the input can't be a valid phone number.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', trim($raw));

        if ($digits === '' || $digits === null) {
            return null;
        }

        // International prefix written as 00 (e.g. 0044...)
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // 91 + Indian mobile → canonical bare 10 digits.
        if (strlen($digits) === 12 && str_starts_with($digits, '91') && preg_match('/^91[6-9]\d{9}$/', $digits)) {
            return substr($digits, 2);
        }

        // Bare Indian mobile.
        if (preg_match('/^[6-9]\d{9}$/', $digits)) {
            return $digits;
        }

        // Anything else must be full international digits (cc + subscriber).
        // E.164 caps at 15 digits; 8 is a practical lower bound.
        if (strlen($digits) >= 8 && strlen($digits) <= 15 && $digits[0] !== '0') {
            return $digits;
        }

        return null;
    }

    /** Canonical stored value is an Indian mobile number. */
    public static function isIndian(string $stored): bool
    {
        return (bool) preg_match('/^[6-9]\d{9}$/', $stored);
    }

    /** cc-prefixed digits for the WhatsApp BSP ('to' field). */
    public static function forWhatsApp(string $stored): string
    {
        $digits = preg_replace('/\D/', '', $stored);

        return self::isIndian($digits) ? '91'.$digits : $digits;
    }

    /** Human-readable form: +91 XXXXX XXXXX for India, +CC… otherwise. */
    public static function forDisplay(string $stored): string
    {
        $digits = preg_replace('/\D/', '', $stored);

        if (self::isIndian($digits)) {
            return '+91 '.substr($digits, 0, 5).' '.substr($digits, 5);
        }

        return '+'.$digits;
    }
}
