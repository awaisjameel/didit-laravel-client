<?php

return [
    // Preferred auth: a single API key sent in the "x-api-key" header. The
    // legacy OAuth2 credentials below are only a fallback when this is unset.
    'api_key' => env('DIDIT_API_KEY'),

    'client_id' => env('DIDIT_CLIENT_ID'),
    'client_secret' => env('DIDIT_CLIENT_SECRET'),

    // Default workflow (UUID from the Didit business console) used when
    // createSession() is not given one explicitly.
    'workflow_id' => env('DIDIT_WORKFLOW_ID'),

    'base_url' => env('DIDIT_BASE_URL', 'https://verification.didit.me'),
    'auth_url' => env('DIDIT_AUTH_URL', 'https://apx.didit.me'),
    'api_version' => env('DIDIT_API_VERSION', 'v3'),

    'webhook_secret' => env('DIDIT_WEBHOOK_SECRET', null),

    'timeout' => env('DIDIT_TIMEOUT', 10), // seconds
    'token_expiry_buffer' => env('DIDIT_TOKEN_EXPIRY_BUFFER', 300), // seconds

    'debug' => env('DIDIT_DEBUG', false),
];
