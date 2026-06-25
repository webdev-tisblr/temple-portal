<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Without this file Laravel falls back to a default that allows all
    | origins ('*'). We restrict browser cross-origin access to the
    | production web domains only. The mobile app is NOT a browser and does
    | not send Origin headers, so it is unaffected by these rules.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://patadiyahanumanji.com',
        'https://www.patadiyahanumanji.com',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
