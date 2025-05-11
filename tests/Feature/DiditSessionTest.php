<?php

use AwaisJameel\DiditLaravelClient\DiditLaravelClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->client = new DiditLaravelClient([
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'base_url' => 'https://verification.didit.me',
        'auth_url' => 'https://apx.didit.me',
    ]);

    // Mock the authentication token response
    Http::fake([
        'apx.didit.me/auth/v2/token/*' => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 3600,
        ]),
    ]);
});

it('creates a verification session', function () {
    Http::fake([
        'verification.didit.me/v1/session*' => Http::response([
            'session_id' => 'test-session-id',
            'verification_url' => 'https://verify.didit.me/test-session',
        ]),
    ]);

    $session = $this->client->createSession(
        'https://example.com/callback',
        'user_identifier',
        ['features' => 'OCR + NFC + FACE']
    );

    expect($session)
        ->toHaveKey('session_id')
        ->toHaveKey('verification_url');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://verification.didit.me/v1/session/' &&
            $request->method() === 'POST' &&
            $request['callback'] === 'https://example.com/callback' &&
            $request['vendor_data'] === 'user_identifier' &&
            $request['features'] === 'OCR + NFC + FACE';
    });
});

it('gets session details', function () {
    Http::fake([
        'verification.didit.me/v1/session/*/decision*' => Http::response([
            'session_id' => 'test-session-id',
            'status' => 'completed',
            'decision' => 'approved',
        ]),
    ]);

    $session = $this->client->getSession('test-session-id');

    expect($session)
        ->toHaveKey('session_id')
        ->toHaveKey('status')
        ->toHaveKey('decision');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://verification.didit.me/v1/session/test-session-id/decision/' &&
            $request->method() === 'GET';
    });
});

it('updates session status', function () {
    Http::fake([
        'verification.didit.me/v1/session/*/update-status*' => Http::response([
            'session_id' => 'test-session-id',
            'status' => 'Approved',
        ]),
    ]);

    $result = $this->client->updateSessionStatus('test-session-id', 'Approved', 'Test comment');

    expect($result)
        ->toHaveKey('session_id')
        ->toHaveKey('status');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://verification.didit.me/v1/session/test-session-id/update-status/' &&
            $request->method() === 'PATCH' &&
            $request['new_status'] === 'Approved' &&
            $request['comment'] === 'Test comment';
    });
});
