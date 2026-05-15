<?php

declare(strict_types=1);

/*
 * Configuration for kreait/laravel-firebase. We only use Cloud Messaging (FCM
 * HTTP v1); other Firebase services are not enabled.
 *
 * The service-account JSON file is generated in Firebase Console →
 * Project Settings → Service accounts → "Generate new private key". On the
 * production server keep it OUTSIDE the web-root (e.g. /home/u123/secure/
 * firebase-credentials.json) and point FIREBASE_CREDENTIALS at that path.
 * Locally, drop it at storage/app/firebase/credentials.json — that path is
 * gitignored via storage/app/.gitignore.
 */
return [

    'default' => env('FIREBASE_PROJECT', 'app'),

    'projects' => [

        'app' => [

            'credentials' => env(
                'FIREBASE_CREDENTIALS',
                storage_path('app/firebase/credentials.json'),
            ),

            'messaging' => [
                'http_client_options' => [
                    'timeout' => 15,
                    'connect_timeout' => 5,
                ],
            ],

            'logging' => [
                'http_log_channel' => null,
                'http_debug_log_channel' => null,
            ],

            'cache_store' => env('FIREBASE_CACHE_STORE', 'file'),

            'debug' => env('FIREBASE_DEBUG', false),
        ],
    ],
];
