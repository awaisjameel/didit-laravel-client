<?php

// config for AwaisJameel/DiditLaravelClient
return [
    /*
    |--------------------------------------------------------------------------
    | DiDiT API Client Configuration
    |--------------------------------------------------------------------------
    |
    | This file is for configuring the DiDiT API client with your application
    | credentials and settings.
    |
    */

    // Required credentials
    'client_id' => env('DIDIT_CLIENT_ID'),
    'client_secret' => env('DIDIT_CLIENT_SECRET'),

    // API endpoints
    'base_url' => env('DIDIT_BASE_URL', 'https://verification.didit.me'),
    'auth_url' => env('DIDIT_AUTH_URL', 'https://apx.didit.me'),

    // Webhook verification
    'webhook_secret' => env('DIDIT_WEBHOOK_SECRET', null),

    // Performance settings
    'timeout' => env('DIDIT_TIMEOUT', 10), // In seconds
    'token_expiry_buffer' => env('DIDIT_TOKEN_EXPIRY_BUFFER', 300), // In seconds default 5 minutes

    // Debugging
    'debug' => env('DIDIT_DEBUG', false),
];
