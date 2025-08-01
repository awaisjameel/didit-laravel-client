# DiDiT Laravel Client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/awaisjameel/didit-laravel-client.svg?style=flat-square)](https://packagist.org/packages/awaisjameel/didit-laravel-client)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/awaisjameel/didit-laravel-client/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/awaisjameel/didit-laravel-client/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/awaisjameel/didit-laravel-client/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/awaisjameel/didit-laravel-client/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/awaisjameel/didit-laravel-client.svg?style=flat-square)](https://packagist.org/packages/awaisjameel/didit-laravel-client)

A Laravel client library for integrating with the DiDiT verification API. This client handles authentication, session management, PDF report generation, and webhook processing.

## Features

-   🔐 OAuth2 Authentication with automatic token management
-   🔄 Session management (create, retrieve, update)
-   📄 PDF report generation
-   🔗 Webhook processing with signature verification
-   ⚡ Request caching and optimization
-   🛡️ Secure webhook signature verification
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
DIDIT_CLIENT_ID=your-client-id
DIDIT_CLIENT_SECRET=your-client-secret
DIDIT_BASE_URL=https://verification.didit.me
DIDIT_AUTH_URL=https://apx.didit.me
DIDIT_WEBHOOK_SECRET=your-webhook-secret
DIDIT_TIMEOUT=10
DIDIT_TOKEN_EXPIRY_BUFFER=300
DIDIT_DEBUG=false
```

## Usage

### Basic Setup

```php
use AwaisJameel\DiditLaravelClient\Facades\DiditLaravelClient;
// or
use AwaisJameel\DiditLaravelClient\DiditLaravelClient;

// Using the facade
$client = DiditLaravelClient::getInstance();

// Or create a new instance with custom configuration
$client = new DiditLaravelClient([
    'client_id' => 'your-client-id',
    'client_secret' => 'your-client-secret',
    // ... other config options
]);
```

### Creating a Verification Session

```php
$session = $client->createSession(
    callbackUrl: 'https://your-app.com/verification/callback',
    vendorData: 101,
    options: [
        'features'=> 'OCR + NFC + FACE'
    ]
);

// The session response contains:
[
    'session_id' => 'xxx-xxx-xxx',
    'verification_url' => 'https://verify.didit.me/xxx'
]
```

### Retrieving Session Details

```php
$sessionDetails = $client->getSession('session-id');

// Response contains verification details:
[
    'session_id' => 'xxx-xxx-xxx',
    'status' => 'completed',
    'decision' => 'approved',
    // ... other session data
]
```

### Updating Session Status

```php
$result = $client->updateSessionStatus(
    sessionId: 'session-id',
    newStatus: 'Approved', // or 'Declined'
    comment: 'Verification approved by admin'
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

    // Handle different webhook events
    match($payload['event']) {
        'verification.completed' => handleVerificationCompleted($payload),
        'verification.expired' => handleVerificationExpired($payload),
        default => handleUnknownEvent($payload)
    };

    return response()->json(['status' => 'processed']);
});
```

Manual webhook signature verification:

```php
$headers = [
    'x-signature' => $request->header('x-signature'),
    'x-timestamp' => $request->header('x-timestamp')
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

The client throws exceptions for various error conditions. It's recommended to wrap API calls in try-catch blocks:

```php
try {
    $session = $client->createSession(...);
} catch (Exception $e) {
    // Handle error
    Log::error('DiDiT API Error: ' . $e->getMessage());
}
```

Common exceptions:

-   Configuration errors (missing credentials)
-   Authentication failures
-   Invalid session IDs
-   Network/API errors
-   Invalid webhook signatures

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

## Testing

The package includes comprehensive tests. Run them with:

```bash
composer test
```

## Security

-   All API requests use HTTPS
-   Webhook signatures are verified using HMAC SHA-256
-   Timing attack safe signature comparison
-   Automatic token expiry management
-   Request timestamp validation

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

-   [Awais Jameel](https://github.com/awaisjameel)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
