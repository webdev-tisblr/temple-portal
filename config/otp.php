<?php

declare(strict_types=1);

/**
 * OTP login configuration.
 *
 * The "review bypass" is a fixed test-login pair for Apple/Google store
 * reviewers. Phone-OTP apps get rejected under App Completeness (Apple
 * Guideline 2.1) when the reviewer — sitting abroad — can't receive the
 * WhatsApp/SMS OTP and is stuck at the login wall. With BOTH values set,
 * the test_phone + test_code log in WITHOUT sending or checking a real
 * OTP, so the reviewer can always get in.
 *
 * Runtime values are admin-editable via temple_system_settings
 * (otp_test_phone / otp_test_code, set on /admin/system-settings); these
 * env entries are only the fallback for local/dev. Leave BOTH empty to
 * disable the bypass entirely — that's the production-safe default.
 *
 * The pair only ever logs into the dedicated test devotee for that phone
 * number; it can never access any real user's account. Use a number you
 * don't mind being publicly known (it goes in the App Store review note).
 */
return [
    /*
     * How long a generated OTP stays valid, in minutes.
     *
     * SINGLE SOURCE OF TRUTH. This value both (a) sets expires_at on the
     * temple_otp_codes row that verification actually enforces, and (b) is
     * the number the OTP message promises the devotee. They used to be two
     * separate literal 10s — one in OtpService::generate(), one hardcoded
     * as '10' in the admin's "Send test OTP" action — which is exactly the
     * kind of drift that has a devotee reading "valid for 10 minutes" on a
     * code that expired five minutes ago. Read it through
     * OtpService::expiryMinutes(), never inline.
     */
    'expiry_minutes' => (int) env('OTP_EXPIRY_MINUTES', 10),

    'review_bypass' => [
        // Must be a valid 10-digit Indian mobile (regex ^[6-9]\d{9}$) and
        // a 6-digit code, to satisfy the auth request validation rules.
        'test_phone' => env('OTP_TEST_PHONE', ''),
        'test_code' => env('OTP_TEST_CODE', ''),
    ],
];
