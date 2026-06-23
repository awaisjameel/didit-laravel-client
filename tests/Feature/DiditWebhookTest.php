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

it('verifies legacy (x-signature) webhook signatures', function () {
    $headers = [
        'x-signature' => $this->signature,
        'x-timestamp' => $this->timestamp,
    ];

    $result = $this->client->verifyWebhookSignature($headers, $this->webhook_payload);

    expect($result)->toBeArray()->toHaveKey('session_id');
});

it('verifies v2 webhook signatures', function () {
    $signatureV2 = hash_hmac('sha256', "{$this->timestamp}:{$this->webhook_payload}", $this->secret);

    $headers = [
        'x-signature-v2' => $signatureV2,
        'x-timestamp' => $this->timestamp,
    ];

    $result = $this->client->verifyWebhookSignature($headers, $this->webhook_payload);

    expect($result)->toBeArray()->toHaveKey('webhook_type');
});

it('verifies v2 webhook signatures without the timestamp prefix', function () {
    // The Didit demo signs the canonical body alone (no "{timestamp}:" prefix).
    $signatureV2 = hash_hmac('sha256', $this->webhook_payload, $this->secret);

    $headers = [
        'x-signature-v2' => $signatureV2,
        'x-timestamp' => $this->timestamp,
    ];

    $result = $this->client->verifyWebhookSignature($headers, $this->webhook_payload);

    expect($result)->toBeArray()->toHaveKey('webhook_type');
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

    $signatureV2 = hash_hmac('sha256', "{$this->timestamp}:{$canonical}", $this->secret);

    $headers = [
        'x-signature-v2' => $signatureV2,
        'x-timestamp' => $this->timestamp,
    ];

    $result = $this->client->verifyWebhookSignature($headers, $unsortedBody);

    expect($result)->toBeArray()->toHaveKey('session_id');
});

it('verifies simple webhook signatures', function () {
    $message = "{$this->timestamp}:test-session-id:Approved:status.updated";
    $signatureSimple = hash_hmac('sha256', $message, $this->secret);

    $headers = [
        'x-signature-simple' => $signatureSimple,
        'x-timestamp' => $this->timestamp,
    ];

    $result = $this->client->verifyWebhookSignature($headers, $this->webhook_payload);

    expect($result)->toBeArray()->toHaveKey('status');
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
