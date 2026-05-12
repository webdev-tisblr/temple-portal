<?php

declare(strict_types=1);

/**
 * Defaults for the SMS provider. The actual values used at runtime are
 * pulled from temple_system_settings.sms_* rows (set by an admin in
 * /admin/system-settings → Integrations → SMS), with these as the
 * fallback when a value is missing — useful for local dev.
 */
return [
    'provider' => env('SMS_PROVIDER', 'msg91'),

    'msg91' => [
        // MSG91's primary OTP / Flow endpoint root.
        'api_url' => env('MSG91_API_URL', 'https://control.msg91.com/api/v5'),
        'auth_key' => env('MSG91_AUTH_KEY'),
        'sender_id' => env('MSG91_SENDER_ID'),
        'otp_template_id' => env('MSG91_OTP_TEMPLATE_ID'),
        'dlt_te_id' => env('MSG91_DLT_TE_ID'),
        'country_code' => env('MSG91_COUNTRY_CODE', '91'),
    ],
];
