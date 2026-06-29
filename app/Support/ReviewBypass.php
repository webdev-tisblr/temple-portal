<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;

/**
 * Single source of truth for the store-review test-login bypass.
 *
 * When BOTH a test phone and test code are configured (admin settings
 * override the config/otp.php env fallback), that pair logs in WITHOUT a
 * real OTP and notifications to that number are suppressed — so Apple/
 * Google reviewers get a clean, message-free login on a throwaway account.
 *
 * Disabled (all methods inert) whenever either half is blank, which is the
 * production-safe default. See config/otp.php.
 */
final class ReviewBypass
{
    public static function testPhone(): string
    {
        return trim((string) SystemSetting::getValue(
            'otp_test_phone',
            (string) config('otp.review_bypass.test_phone', ''),
        ));
    }

    public static function testCode(): string
    {
        return trim((string) SystemSetting::getValue(
            'otp_test_code',
            (string) config('otp.review_bypass.test_code', ''),
        ));
    }

    /** The bypass is only active when BOTH halves are configured. */
    public static function isEnabled(): bool
    {
        return self::testPhone() !== '' && self::testCode() !== '';
    }

    /** True when $phone is the configured review test number. */
    public static function isTestPhone(?string $phone): bool
    {
        if (! self::isEnabled() || $phone === null || $phone === '') {
            return false;
        }

        return hash_equals(self::testPhone(), trim($phone));
    }

    /** True when the phone + code pair matches the configured bypass. */
    public static function matches(string $phone, string $code): bool
    {
        return self::isEnabled()
            && hash_equals(self::testPhone(), trim($phone))
            && hash_equals(self::testCode(), trim($code));
    }
}
