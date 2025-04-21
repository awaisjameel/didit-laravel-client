<?php

use AwaisJameel\DiditLaravelClient\DiditLaravelClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->validConfig = [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'base_url' => 'https://verification.didit.me',
        'auth_url' => 'https://apx.didit.me',
        'webhook_secret' => 'test-webhook-secret',
    ];

    $this->client = new DiditLaravelClient($this->validConfig);
});

it('validates required configuration', function () {
    expect(fn () => new DiditLaravelClient([]))
        ->toThrow(Exception::class, 'DIDIT_CLIENT_ID is required');

    expect(fn () => new DiditLaravelClient(['client_id' => 'test']))
        ->toThrow(Exception::class, 'DIDIT_CLIENT_SECRET is required');
});

it('gets and caches access token', function () {
    Http::fake([
        'apx.didit.me/auth/v2/token/*' => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 3600,
        ]),
    ]);

    $token = $this->client->getAccessToken();
    expect($token)->toBe('test-token');

    // Should use cached token on second call
    $secondToken = $this->client->getAccessToken();
    expect($secondToken)->toBe('test-token');

    Http::assertSentCount(1);
});

it('sends correct authentication headers', function () {
    Http::fake([
        'apx.didit.me/auth/v2/token/*' => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 3600,
        ]),
    ]);

    $this->client->getAccessToken();

    Http::assertSent(function (Request $request) {
        $authHeader = $request->header('Authorization')[0] ?? '';
        $expectedAuth = 'Basic '.base64_encode('test-client-id:test-client-secret');

        return $authHeader === $expectedAuth &&
            $request->header('Content-Type')[0] === 'application/x-www-form-urlencoded' &&
            $request->url() === 'https://apx.didit.me/auth/v2/token/';
    });
});
