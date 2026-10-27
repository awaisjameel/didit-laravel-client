<?php

return [
    // Current API authentication: a server-side API key in the x-api-key header.
    'api_key' => env('DIDIT_API_KEY'),

    // Default workflow (UUID from the Didit business console) used when
    // createSession() is not given one explicitly.
    'workflow_id' => env('DIDIT_WORKFLOW_ID'),

    'base_url' => env('DIDIT_BASE_URL', 'https://verification.didit.me'),
    'api_version' => env('DIDIT_API_VERSION', 'v3'),

    'webhook_secret' => env('DIDIT_WEBHOOK_SECRET', null),

    'timeout' => env('DIDIT_TIMEOUT', 10), // seconds

    'debug' => env('DIDIT_DEBUG', false),
];
