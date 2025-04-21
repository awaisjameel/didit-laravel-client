<?php

use AwaisJameel\DiditLaravelClient\DiditLaravelClient;
use Carbon\Carbon;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->client = new DiditLaravelClient([
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'base_url' => 'https://verification.didit.me',
        'auth_url' => 'https://apx.didit.me',
        'webhook_secret' => 'test-webhook-secret',
    ]);

    $this->webhook_payload = json_encode([
        'event' => 'verification.completed',
        'session_id' => 'test-session-id',
        'status' => 'approved',
    ]);

    Carbon::setTestNow('2024-01-01 12:00:00');
    $this->timestamp = Carbon::now()->timestamp;
    $this->signature = hash_hmac('sha256', $this->webhook_payload, 'test-webhook-secret');
});

it('verifies valid webhook signatures', function () {
    $headers = [
        'x-signature' => $this->signature,
        'x-timestamp' => $this->timestamp,
    ];

    $result = $this->client->verifyWebhookSignature($headers, $this->webhook_payload);

    expect($result)
        ->toBeArray()
        ->toHaveKey('event')
        ->toHaveKey('session_id')
        ->toHaveKey('status');
});

it('rejects invalid webhook signatures', function () {
    $headers = [
        'x-signature' => 'invalid-signature',
        'x-timestamp' => $this->timestamp,
    ];

    expect(fn () => $this->client->verifyWebhookSignature($headers, $this->webhook_payload))
        ->toThrow(Exception::class, 'Invalid webhook signature');
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
        ->toHaveKey('event')
        ->toHaveKey('session_id')
        ->toHaveKey('status');
});
