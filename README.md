# DiDiT Laravel Client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/awaisjameel/didit-laravel-client.svg?style=flat-square)](https://packagist.org/packages/awaisjameel/didit-laravel-client)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/awaisjameel/didit-laravel-client/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/awaisjameel/didit-laravel-client/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/awaisjameel/didit-laravel-client/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/awaisjameel/didit-laravel-client/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/awaisjameel/didit-laravel-client.svg?style=flat-square)](https://packagist.org/packages/awaisjameel/didit-laravel-client)

A Laravel client library for integrating with the DiDiT verification API (v3). This client handles authentication, session management, PDF report generation, and webhook processing.

## Features

-   🔐 API key authentication (`x-api-key`), with a legacy OAuth2 fallback
-   🔄 Session management (create, retrieve, update) on the Didit **v3** API
-   🧩 Workflow-based sessions (`workflow_id`)
-   📄 PDF report generation
-   🔗 Webhook processing with multiple signature schemes (V2, Simple, legacy)
-   📣 `DiditWebhookReceived` event for listener-based webhook handling
-   🛡️ Timing-attack-safe signature verification
-   🧯 Typed exception hierarchy (`DiditException` and friends)
-   📝 Comprehensive logging options

## Installation

You can install the package via composer:

```bash
composer require awaisjameel/didit-laravel-client
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --provider="AwaisJameel\DiditLaravelClient\DiditLaravelClientServiceProvider"
```

Add the following environment variables to your `.env` file:

```env
# Authentication (recommended): grab your API key from https://business.didit.me
DIDIT_API_KEY=your-api-key

# Default workflow used when creating sessions (configured in the Didit console)
DIDIT_WORKFLOW_ID=your-workflow-uuid

# Endpoints
DIDIT_BASE_URL=https://verification.didit.me
DIDIT_API_VERSION=v3

# Webhooks
DIDIT_WEBHOOK_SECRET=your-webhook-secret

# Misc
DIDIT_TIMEOUT=10
DIDIT_DEBUG=false

# Legacy OAuth2 fallback — only needed if you are NOT using an API key
# DIDIT_CLIENT_ID=your-client-id
# DIDIT_CLIENT_SECRET=your-client-secret
# DIDIT_AUTH_URL=https://apx.didit.me
# DIDIT_TOKEN_EXPIRY_BUFFER=300
```

> **Authentication:** The current Didit API authenticates with a single API key sent in the
> `x-api-key` header — just set `DIDIT_API_KEY`. The legacy OAuth2 `client_credentials`
> flow is used automatically only when no API key is configured.

## Usage

### Basic Setup

```php
use AwaisJameel\DiditLaravelClient\Facades\DiditLaravelClient;
// or
use AwaisJameel\DiditLaravelClient\DiditLaravelClient;

// Resolve the shared instance from the container (reads config/.env)
$client = app(DiditLaravelClient::class);

// Or call methods statically through the facade
DiditLaravelClient::createSession(/* ... */);

// Or create a new instance with custom configuration
$client = new DiditLaravelClient([
    'api_key' => 'your-api-key',
    // ... other config options
]);
```

### Creating a Verification Session

A session is tied to a **workflow** (configured in the Didit console and identified by a UUID).
Provide it via the `workflow_id` option, or set a default with `DIDIT_WORKFLOW_ID`.

```php
$session = $client->createSession(
    callbackUrl: 'https://your-app.com/verification/callback', // optional
    vendorData: 'user-123',                                    // optional
    options: [
        'workflow_id' => 'your-workflow-uuid', // falls back to DIDIT_WORKFLOW_ID
        // Any other v3 session fields, e.g.:
        // 'callback_method' => 'both',
        // 'metadata' => json_encode(['plan' => 'pro']),
        // 'contact_details' => ['email' => 'jane@example.com'],
        // 'expected_details' => ['first_name' => 'Jane', 'last_name' => 'Doe'],
    ]
);

// The session response contains:
[
    'session_id' => 'xxx-xxx-xxx',
    'url' => 'https://verify.didit.me/session/xxx',
    // ...
]
```

> If you set `DIDIT_WORKFLOW_ID`, you can simply call `$client->createSession()`.

### Retrieving Session Details

```php
$sessionDetails = $client->getSession('session-id');

// Response contains the full verification decision:
[
    'session_id' => 'xxx-xxx-xxx',
    'status' => 'Approved',
    'id_verifications' => [/* ... */],
    'face_matches' => [/* ... */],
    // ... other decision data
]
```

### Updating Session Status

```php
$result = $client->updateSessionStatus(
    sessionId: 'session-id',
    newStatus: 'Approved', // 'Approved', 'Declined' or 'Resubmitted'
    comment: 'Verification approved by admin',
    options: [
        // Optional extra fields, e.g.:
        // 'send_email' => true,
        // 'email_address' => 'jane@example.com',
    ]
);
```

### Generating PDF Reports

```php
$pdfContent = $client->generateSessionPDF('session-id');

// Save to file
file_put_contents('verification-report.pdf', $pdfContent);

// Or return as download response
return response($pdfContent)
    ->header('Content-Type', 'application/pdf')
    ->header('Content-Disposition', 'attachment; filename="report.pdf"');
```

### Handling Webhooks

Set up your webhook route in `routes/web.php`:

```php
Route::post('didit/webhook', function (Request $request) {
    $payload = DiditLaravelClient::processWebhook($request);

    // Handle different webhook events by status / type
    match($payload['status'] ?? null) {
        'Approved' => handleApproved($payload),
        'Declined' => handleDeclined($payload),
        default => handleOther($payload)
    };

    return response()->json(['status' => 'processed']);
});
```

`processWebhook()` automatically tries every signature scheme Didit sends
(`x-signature-v2`, `x-signature-simple`, then the legacy `x-signature`) and
validates the request freshness using `x-timestamp`.

#### Listening for webhook events

After a webhook is verified, `processWebhook()` dispatches a
`DiditWebhookReceived` event carrying the verified payload, so you can react in
a listener instead of handling everything inline:

```php
use AwaisJameel\DiditLaravelClient\Events\DiditWebhookReceived;
use Illuminate\Support\Facades\Event;

Event::listen(function (DiditWebhookReceived $event) {
    match ($event->payload['status'] ?? null) {
        'Approved' => handleApproved($event->payload),
        'Declined' => handleDeclined($event->payload),
        default => handleOther($event->payload),
    };
});
```

Manual webhook signature verification:

```php
$headers = [
    'x-signature' => $request->header('x-signature'),
    'x-signature-v2' => $request->header('x-signature-v2'),
    'x-signature-simple' => $request->header('x-signature-simple'),
    'x-timestamp' => $request->header('x-timestamp'),
];

try {
    $payload = $client->verifyWebhookSignature($headers, $request->getContent());
    // Process verified webhook payload
} catch (Exception $e) {
    // Handle invalid signature
    return response()->json(['error' => $e->getMessage()], 400);
}
```

### Error Handling

The client throws a small, typed exception hierarchy so you can catch broadly or
narrowly. Every package exception extends `DiditException`, which itself extends
`\Exception`:

| Exception | When it's thrown |
| --- | --- |
| `DiditException` | Base class — catch this to handle any DiDiT failure |
| `DiditConfigurationException` | Missing config (API key/OAuth credentials, base URL, `workflow_id`, webhook secret) |
| `DiditAuthenticationException` | Legacy OAuth2 token request failed |
| `DiditRequestException` | An API request failed (non-2xx or transport error); exposes `getResponse()` and `status()` |
| `WebhookVerificationException` | Webhook body/signature/timestamp could not be verified |

Invalid method arguments (e.g. an empty session id or an unknown status) throw
`\InvalidArgumentException`.

```php
use AwaisJameel\DiditLaravelClient\Exceptions\DiditException;
use AwaisJameel\DiditLaravelClient\Exceptions\DiditRequestException;

try {
    $session = $client->createSession(/* ... */);
} catch (DiditRequestException $e) {
    // Inspect the HTTP response from Didit
    Log::error('DiDiT API Error', [
        'status' => $e->status(),
        'body' => optional($e->getResponse())->json(),
    ]);
} catch (DiditException $e) {
    // Any other DiDiT failure (config, auth, webhook, ...)
    Log::error('DiDiT Error: '.$e->getMessage());
}
```

### Debugging

Enable debug mode in your configuration to get detailed logging:

```php
// In your .env file
DIDIT_DEBUG=true

// Or in configuration
$client = new DiditLaravelClient([
    // ... other config
    'debug' => true
]);
```

Debug logs will include:

-   API requests and responses
-   Token management events
-   Webhook processing details
-   Error details

> ⚠️ **Debug logs may contain PII.** When `DIDIT_DEBUG=true`, verification
> payloads and API responses (which can include personal/identity data) are
> written to your application log. Keep debug mode off in production, or ensure
> your logs are access-controlled and retained appropriately.

## Testing

The package includes comprehensive tests. Run them with:

```bash
composer test
```

## Security

-   All API requests use HTTPS
-   Webhook signatures are verified using HMAC SHA-256 (V2, Simple and legacy schemes)
-   Timing attack safe signature comparison
-   API key sent via the `x-api-key` header. On the legacy OAuth fallback, access tokens are cached through Laravel's cache store (shared across requests/workers) and refreshed automatically before expiry
-   Request timestamp validation (rejects stale webhooks older than 5 minutes)

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

-   [Awais Jameel](https://github.com/awaisjameel)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
