<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Native app association (universal / App Links)
    |--------------------------------------------------------------------------
    |
    | Today the ONLY channel that can open the app from the browser is the
    | patadiyahanumanji:// custom scheme, which always shows Safari's
    | "Open in …?" alert. Universal Links / App Links remove that alert,
    | but they need two things this repo cannot invent:
    |
    |   • APPLE_TEAM_ID  — the 10-character Apple Developer Team ID.
    |   • ANDROID_APP_SHA256 — the release keystore's SHA-256 fingerprint
    |     (colon-separated, as printed by `keytool -list -v`). Comma-separate
    |     several if upload + Play signing keys differ.
    |
    | While either is unset the matching /.well-known/ route answers 404,
    | which is exactly what Apple/Google expect from a site that has not
    | claimed the app — far safer than publishing a file with a placeholder
    | team id, which permanently poisons the CDN-cached association.
    |
    | NOTE: serving these does nothing until the app ships
    | com.apple.developer.associated-domains (iOS) / the intent-filter
    | autoVerify (Android). See specs/03-deeplink-contract.md.
    |
    */

    'scheme' => env('APP_DEEP_LINK_SCHEME', 'patadiyahanumanji'),

    'bundle_id' => env('APP_BUNDLE_ID', 'com.patadiyahanumanji.app'),

    'apple_team_id' => env('APPLE_TEAM_ID'),

    'android_sha256' => env('ANDROID_APP_SHA256'),

    /** Paths the app is allowed to intercept when association is live. */
    'paths' => ['/donate', '/donate/*', '/dashboard/*', '/r/*'],
];
