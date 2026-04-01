<?php

declare(strict_types=1);

return [
    'api_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v18.0'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
];
