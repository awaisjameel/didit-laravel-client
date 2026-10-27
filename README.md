# DiDiT Laravel Client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/awaisjameel/didit-laravel-client.svg?style=flat-square)](https://packagist.org/packages/awaisjameel/didit-laravel-client)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/awaisjameel/didit-laravel-client/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/awaisjameel/didit-laravel-client/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/awaisjameel/didit-laravel-client.svg?style=flat-square)](https://packagist.org/packages/awaisjameel/didit-laravel-client)

A Laravel client library for integrating with the DiDiT verification API (v3). This client handles authentication, session management, PDF report generation, and webhook processing.

## Features

-   🔐 API key authentication (`x-api-key`)
-   🔄 Session management (create, retrieve, update) on the Didit **v3** API
-   🧩 Workflow-based sessions (`workflow_id`)
-   📄 PDF report generation
-   🔗 Full-payload webhook signatures (V2 and raw-body), including secret rotation
-   📣 `DiditWebhookReceived` event for listener-based webhook handling
-   🛡️ Timing-attack-safe signature verification
-   🧯 Typed exception hierarchy (`DiditException` and friends)
-   📝 Comprehensive logging options

## Requirements and upgrading to 3.0

PHP 8.2–8.5 with Laravel 12, or PHP 8.3–8.5 with Laravel 13.

Version 3 requires `DIDIT_API_KEY`. The legacy OAuth2 fallback, `getAccessToken()`,
`DiditAuthenticationException`, and OAuth configuration keys have been removed.
API authentication failures are `DiditRequestException` instances; inspect
`status()` for the HTTP status.

Webhooks require a full-payload `X-Signature-V2` or `X-Signature`, including their
`-Previous` variants during Didit's secret rotation window. Simple-only signatures
and the old timestamp-prefixed V2 variant are rejected. Freshness is checked against
the signed body's `timestamp` (or signed legacy `created_at`); a supplied
`X-Timestamp` must match it. The current protocol is documented in
[Didit's webhook guide](https://docs.didit.me/integration/webhooks).

Successful JSON endpoints must return JSON objects, PDF responses must start with
`%PDF`, and HTTP redirects are rejected. Session identifiers are encoded as path
segments. Existing create/retrieve/update/PDF method names remain available.

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

```

> **Authentication:** The current Didit API authenticates with a single API key sent in the
> `x-api-key` header — set `DIDIT_API_KEY` as described in
> [Didit's authentication guide](https://docs.didit.me/getting-started/api-authentication).

## Usage

### Basic Setup

```php
use AwaisJameel\DiditLaravelClient\Facades\DiditLaravelClient as Didit;
use AwaisJameel\DiditLaravelClient\DiditLaravelClient;

// Resolve the shared instance from the container (reads config/.env)
$client = app(DiditLaravelClient::class);

// Or call methods statically through the facade
Didit::createSession(/* ... */);

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
        // 'metadata' => ['plan' => 'pro'],
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

Register the webhook in your application's API routes (normally `routes/api.php`)
so browser CSRF middleware does not reject provider deliveries. With Laravel's
default API prefix, configure Didit's destination as `https://your-app.com/api/didit/webhook`.
If API routes are not enabled, register the file through `withRouting(api: ...)`
in `bootstrap/app.php` as described in [Laravel's routing documentation](https://laravel.com/docs/13.x/routing).

```php
use AwaisJameel\DiditLaravelClient\Exceptions\WebhookVerificationException;
use AwaisJameel\DiditLaravelClient\Facades\DiditLaravelClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('didit/webhook', function (Request $request) {
    try {
        DiditLaravelClient::processWebhook($request);
    } catch (WebhookVerificationException) {
        abort(401, 'Invalid webhook');
    }

    return response()->json(['received' => true]);
});
```

`processWebhook()` verifies the complete payload and its signed timestamp before
dispatching `DiditWebhookReceived`. Use the event listener for application-specific
processing; queue slow work so the route responds quickly. Use `event_id` for
idempotency, because legitimate provider retries may dispatch the same event again.

Pass the untouched request body and both full-payload signature headers. Keep PHP's
`serialize_precision=-1` default for V2 numeric encoding. If canonical encoding
differs, the raw-body signature can verify the original bytes; middleware must not
re-encode them. See [Didit's PHP webhook guidance](https://docs.didit.me/integration/webhooks#php--laravel-recommended-x-signature-v2).

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
    'x-signature-v2-previous' => $request->header('x-signature-v2-previous'),
    'x-signature-previous' => $request->header('x-signature-previous'),
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
| `DiditConfigurationException` | Missing or invalid API key, base URL, timeout, `workflow_id`, or webhook secret |
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
        'message' => $e->getMessage(),
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

-   Webhook event types after verification
-   Error classes and HTTP status codes

## Testing

The package includes comprehensive tests. Run them with:

```bash
composer test
```

## Security

-   The default API endpoint uses HTTPS
-   Full-payload webhook signatures use HMAC SHA-256 (V2 or raw-body)
-   Timing attack safe signature comparison
-   API keys are sent in the `x-api-key` header; redirects are rejected
-   Signed payload timestamps must be within five minutes of the current time

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

-   [Awais Jameel](https://github.com/awaisjameel)
-   [All Contributors](https://github.com/awaisjameel/didit-laravel-client/graphs/contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
