<?php

use AwaisJameel\DiditLaravelClient\DiditLaravelClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('requires authentication credentials', function () {
    // Empty strings override the global test config so neither an API key nor
    // OAuth credentials are present.
    expect(fn () => new DiditLaravelClient([
        'api_key' => '',
        'client_id' => '',
        'client_secret' => '',
    ]))->toThrow(Exception::class, 'DIDIT_API_KEY is required');
});

it('sends the api key header on requests', function () {
    Http::fake([
        'verification.didit.me/v3/session/*/decision*' => Http::response(['session_id' => 'x']),
    ]);

    $client = new DiditLaravelClient([
        'api_key' => 'test-api-key',
        'base_url' => 'https://verification.didit.me',
    ]);

    $client->getSession('test-session-id');

    Http::assertSent(fn (Request $request) => $request->header('x-api-key')[0] === 'test-api-key');
});

it('gets and caches an access token using the legacy OAuth flow', function () {
    Http::fake([
        'apx.didit.me/auth/v2/token/*' => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 3600,
        ]),
    ]);

    $client = new DiditLaravelClient([
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'base_url' => 'https://verification.didit.me',
        'auth_url' => 'https://apx.didit.me',
    ]);

    expect($client->getAccessToken())->toBe('test-token');
    // Should use the cached token on the second call
    expect($client->getAccessToken())->toBe('test-token');

    Http::assertSentCount(1);
});

it('sends correct OAuth authentication headers', function () {
    Http::fake([
        'apx.didit.me/auth/v2/token/*' => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 3600,
        ]),
    ]);

    $client = new DiditLaravelClient([
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'base_url' => 'https://verification.didit.me',
        'auth_url' => 'https://apx.didit.me',
    ]);

    $client->getAccessToken();

    Http::assertSent(function (Request $request) {
        $authHeader = $request->header('Authorization')[0] ?? '';
        $expectedAuth = 'Basic '.base64_encode('test-client-id:test-client-secret');

        return $authHeader === $expectedAuth &&
            $request->header('Content-Type')[0] === 'application/x-www-form-urlencoded' &&
            $request->url() === 'https://apx.didit.me/auth/v2/token/';
    });
});

it('uses the legacy bearer token when no api key is set', function () {
    Http::fake([
        'apx.didit.me/auth/v2/token/*' => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 3600,
        ]),
        'verification.didit.me/v3/session/*/decision*' => Http::response(['session_id' => 'x']),
    ]);

    $client = new DiditLaravelClient([
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'base_url' => 'https://verification.didit.me',
        'auth_url' => 'https://apx.didit.me',
    ]);

    $client->getSession('test-session-id');

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), '/decision/')) {
            return false;
        }

        return ($request->header('Authorization')[0] ?? '') === 'Bearer test-token';
    });
});
