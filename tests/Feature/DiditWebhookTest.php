<?php

use AwaisJameel\DiditLaravelClient\DiditLaravelClient;
use AwaisJameel\DiditLaravelClient\Events\DiditWebhookReceived;
use AwaisJameel\DiditLaravelClient\Exceptions\WebhookVerificationException;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->secret = 'test-webhook-secret';

    $this->client = new DiditLaravelClient([
        'api_key' => 'test-api-key',
        'base_url' => 'https://verification.didit.me',
        'webhook_secret' => $this->secret,
    ]);

    Carbon::setTestNow('2024-01-01 12:00:00');
    $this->timestamp = Carbon::now()->timestamp;

    // Keys are already in sorted order so the raw body matches the canonical
    // form the V2 signature is computed over.
    $this->webhook_payload = json_encode([
        'session_id' => 'test-session-id',
        'status' => 'Approved',
        'timestamp' => $this->timestamp,
        'vendor_data' => 'user-123',
        'webhook_type' => 'status.updated',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $this->signature = hash_hmac('sha256', $this->webhook_payload, $this->secret);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('verifies legacy (x-signature) webhook signatures', function () {
    $headers = [
        'x-signature' => $this->signature,
        'x-timestamp' => $this->timestamp,
    ];

    $result = $this->client->verifyWebhookSignature($headers, $this->webhook_payload);

    expect($result)->toBeArray()->toHaveKey('session_id');
});

it('verifies v2 webhook signatures', function () {
    $signatureV2 = hash_hmac('sha256', $this->webhook_payload, $this->secret);

    $headers = [
        'x-signature-v2' => $signatureV2,
        'x-timestamp' => $this->timestamp,
    ];

    $result = $this->client->verifyWebhookSignature($headers, $this->webhook_payload);

    expect($result)->toBeArray()->toHaveKey('webhook_type');
});

it('rejects obsolete timestamp-prefixed v2 signatures', function () {
    $signatureV2 = hash_hmac('sha256', "{$this->timestamp}:{$this->webhook_payload}", $this->secret);

    $headers = [
        'x-signature-v2' => $signatureV2,
        'x-timestamp' => $this->timestamp,
    ];

    expect(fn () => $this->client->verifyWebhookSignature($headers, $this->webhook_payload))
        ->toThrow(WebhookVerificationException::class);
});

it('verifies v2 signatures when the body keys are not pre-sorted', function () {
    // Body keys are intentionally out of order; the client must canonicalize
    // (sort keys) before hashing to match Didit's signature.
    $unsortedBody = json_encode([
        'webhook_type' => 'status.updated',
        'status' => 'Approved',
        'vendor_data' => 'user-123',
        'session_id' => 'test-session-id',
        'timestamp' => $this->timestamp,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Didit signs the canonical (sorted-key) form, so build that here.
    $canonical = json_encode([
        'session_id' => 'test-session-id',
        'status' => 'Approved',
        'timestamp' => $this->timestamp,
        'vendor_data' => 'user-123',
        'webhook_type' => 'status.updated',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $signatureV2 = hash_hmac('sha256', $canonical, $this->secret);

    $headers = [
        'x-signature-v2' => $signatureV2,
        'x-timestamp' => $this->timestamp,
    ];

    $result = $this->client->verifyWebhookSignature($headers, $unsortedBody);

    expect($result)->toBeArray()->toHaveKey('session_id');
});

it('rejects simple-only webhook signatures', function () {
    $message = "{$this->timestamp}:test-session-id:Approved:status.updated";
    $signatureSimple = hash_hmac('sha256', $message, $this->secret);

    $headers = [
        'x-signature-simple' => $signatureSimple,
        'x-timestamp' => $this->timestamp,
    ];

    expect(fn () => $this->client->verifyWebhookSignature($headers, $this->webhook_payload))
        ->toThrow(WebhookVerificationException::class);
});

it('falls back to the timestamp in the payload body', function () {
    $headers = ['x-signature' => $this->signature];

    $result = $this->client->verifyWebhookSignature($headers, $this->webhook_payload);

    expect($result)->toBeArray()->toHaveKey('session_id');
});

it('rejects invalid webhook signatures', function () {
    $headers = [
        'x-signature' => 'invalid-signature',
        'x-timestamp' => $this->timestamp,
    ];

    expect(fn () => $this->client->verifyWebhookSignature($headers, $this->webhook_payload))
        ->toThrow(WebhookVerificationException::class, 'Invalid webhook signature');
});

it('rejects stale webhook timestamps', function () {
    $staleTimestamp = Carbon::now()->subMinutes(6)->timestamp;

    $headers = [
        'x-signature' => $this->signature,
        'x-timestamp' => $staleTimestamp,
    ];

    expect(fn () => $this->client->verifyWebhookSignature($headers, $this->webhook_payload))
        ->toThrow(Exception::class, 'Request timestamp is stale');
});

it('processes valid webhook requests', function () {
    $request = Request::create(
        '/',
        'POST',
        [],
        [],
        [],
        [
            'HTTP_X_SIGNATURE' => $this->signature,
            'HTTP_X_TIMESTAMP' => $this->timestamp,
        ],
        $this->webhook_payload
    );

    $result = $this->client->processWebhook($request);

    expect($result)
        ->toBeArray()
        ->toHaveKey('session_id')
        ->toHaveKey('status');
});

it('dispatches a DiditWebhookReceived event after processing', function () {
    Event::fake();

    $request = Request::create(
        '/',
        'POST',
        [],
        [],
        [],
        [
            'HTTP_X_SIGNATURE' => $this->signature,
            'HTTP_X_TIMESTAMP' => $this->timestamp,
        ],
        $this->webhook_payload
    );

    $this->client->processWebhook($request);

    Event::assertDispatched(
        DiditWebhookReceived::class,
        fn (DiditWebhookReceived $event) => ($event->payload['session_id'] ?? null) === 'test-session-id'
    );
});

it('rejects an expired signed payload even when the unsigned timestamp header is refreshed', function () {
    $body = json_encode([
        'session_id' => 'test-session-id',
        'status' => 'Approved',
        'timestamp' => $this->timestamp - 3600,
        'webhook_type' => 'status.updated',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    foreach (['x-signature-v2', 'x-signature'] as $header) {
        $signature = hash_hmac('sha256', $body, $this->secret);
        expect(fn () => $this->client->verifyWebhookSignature([
            $header => $signature,
            'x-timestamp' => (string) $this->timestamp,
        ], $body))->toThrow(WebhookVerificationException::class);
    }
});

it('does not trust a decision authenticated only by the deprecated simple signature', function () {
    $body = json_encode([
        'session_id' => 'test-session-id',
        'status' => 'Approved',
        'timestamp' => $this->timestamp,
        'webhook_type' => 'status.updated',
        'decision' => ['id_verifications' => [['status' => 'Approved', 'full_name' => 'Tampered identity']]],
    ]);
    $signature = hash_hmac('sha256', "{$this->timestamp}:test-session-id:Approved:status.updated", $this->secret);

    expect(fn () => $this->client->verifyWebhookSignature([
        'x-signature-simple' => $signature,
        'x-timestamp' => (string) $this->timestamp,
    ], $body))->toThrow(WebhookVerificationException::class);
});

it('preserves empty objects and numeric object keys during canonical signature verification', function () {
    $body = '{"status":"Approved","metadata":{"keys":{"2":"two","10":"ten"},"empty":{}},"timestamp":'.$this->timestamp.',"session_id":"test-session-id"}';
    $canonical = '{"metadata":{"empty":{},"keys":{"10":"ten","2":"two"}},"session_id":"test-session-id","status":"Approved","timestamp":'.$this->timestamp.'}';

    expect($this->client->verifyWebhookSignature([
        'x-signature-v2' => hash_hmac('sha256', $canonical, $this->secret),
        'x-timestamp' => (string) $this->timestamp,
    ], $body))->toHaveKey('session_id', 'test-session-id');
});

it('accepts the previous full-payload signature during the documented secret rotation window', function () {
    expect($this->client->verifyWebhookSignature([
        'x-signature-v2' => str_repeat('0', 64),
        'x-signature-v2-previous' => hash_hmac('sha256', $this->webhook_payload, $this->secret),
        'x-timestamp' => (string) $this->timestamp,
    ], $this->webhook_payload))->toHaveKey('session_id', 'test-session-id');
});

it('does not fall back to a simple signature when a full-payload signature fails', function () {
    $simple = hash_hmac('sha256', "{$this->timestamp}:test-session-id:Approved:status.updated", $this->secret);

    expect(fn () => $this->client->verifyWebhookSignature([
        'x-signature-v2' => str_repeat('0', 64),
        'x-signature-simple' => $simple,
        'x-timestamp' => (string) $this->timestamp,
    ], $this->webhook_payload))->toThrow(WebhookVerificationException::class);
});

it('rejects unsupported timestamp values even with a correct full-payload signature', function (mixed $timestamp) {
    $body = json_encode(['timestamp' => $timestamp]);

    expect(fn () => $this->client->verifyWebhookSignature([
        'x-signature' => hash_hmac('sha256', $body, $this->secret),
    ], $body))->toThrow(WebhookVerificationException::class);
})->with([[null], [true], [[]], ['now'], ['99999999999999999999999999']]);

it('accepts signed legacy ISO timestamps without trusting an unsigned replacement', function () {
    $body = json_encode(['created_at' => '2024-01-01T12:00:00Z', 'session_id' => 'test']);

    expect($this->client->verifyWebhookSignature([
        'x-signature' => hash_hmac('sha256', $body, $this->secret),
    ], $body))->toHaveKey('session_id', 'test');
});

it('rejects ambiguous duplicate header casing', function () {
    expect(fn () => $this->client->verifyWebhookSignature([
        'X-Signature' => $this->signature,
        'x-signature' => str_repeat('0', 64),
    ], $this->webhook_payload))->toThrow(WebhookVerificationException::class, 'Ambiguous');
});

it('does not dispatch an event for an unverified request', function () {
    Event::fake();
    $request = Request::create('/', 'POST', server: ['HTTP_X_SIGNATURE' => str_repeat('0', 64)], content: $this->webhook_payload);

    try {
        $this->client->processWebhook($request);
        $this->fail('Expected invalid signature rejection.');
    } catch (WebhookVerificationException) {
        Event::assertNotDispatched(DiditWebhookReceived::class);
    }
});

it('canonicalizes Unicode line separators without escaping them', function () {
    $body = '{"timestamp":'.$this->timestamp.',"metadata":{"name":"José\u2028example"}}';
    $canonical = '{"metadata":{"name":"José'."\u{2028}".'example"},"timestamp":'.$this->timestamp.'}';

    expect($this->client->verifyWebhookSignature([
        'X-Signature-V2' => strtoupper(hash_hmac('sha256', $canonical, $this->secret)),
    ], $body)['metadata']['name'])->toBe("José\u{2028}example");
});

it('collects previous-secret headers when processing a Laravel request', function () {
    Event::fake();
    $request = Request::create('/', 'POST', server: [
        'HTTP_X_SIGNATURE_PREVIOUS' => $this->signature,
        'HTTP_X_TIMESTAMP' => (string) $this->timestamp,
    ], content: $this->webhook_payload);

    $this->client->processWebhook($request);

    Event::assertDispatched(DiditWebhookReceived::class);
});
