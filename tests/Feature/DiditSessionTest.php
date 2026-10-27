<?php

use AwaisJameel\DiditLaravelClient\DiditLaravelClient;
use AwaisJameel\DiditLaravelClient\Exceptions\DiditRequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->client = new DiditLaravelClient([
        'api_key' => 'test-api-key',
        'base_url' => 'https://verification.didit.me',
    ]);
});

it('creates a verification session', function () {
    Http::fake([
        'verification.didit.me/v3/session*' => Http::response([
            'session_id' => 'test-session-id',
            'url' => 'https://verify.didit.me/session/test-session',
        ], 201),
    ]);

    $session = $this->client->createSession(
        'https://example.com/callback',
        'user_identifier',
        ['workflow_id' => 'workflow-uuid']
    );

    expect($session)
        ->toHaveKey('session_id')
        ->toHaveKey('url');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://verification.didit.me/v3/session/' &&
            $request->method() === 'POST' &&
            $request->header('x-api-key')[0] === 'test-api-key' &&
            $request['workflow_id'] === 'workflow-uuid' &&
            $request['callback'] === 'https://example.com/callback' &&
            $request['vendor_data'] === 'user_identifier';
    });
});

it('falls back to the configured default workflow id', function () {
    Http::fake([
        'verification.didit.me/v3/session*' => Http::response(['session_id' => 'x', 'url' => 'y'], 201),
    ]);

    $client = new DiditLaravelClient([
        'api_key' => 'test-api-key',
        'base_url' => 'https://verification.didit.me',
        'workflow_id' => 'default-workflow',
    ]);

    $client->createSession();

    Http::assertSent(fn ($request) => $request['workflow_id'] === 'default-workflow');
});

it('requires a workflow id', function () {
    expect(fn () => $this->client->createSession('https://example.com/callback'))
        ->toThrow(Exception::class, 'workflow_id is required');
});

it('gets session details', function () {
    Http::fake([
        'verification.didit.me/v3/session/*/decision*' => Http::response([
            'session_id' => 'test-session-id',
            'status' => 'Approved',
            'decision' => [],
        ]),
    ]);

    $session = $this->client->getSession('test-session-id');

    expect($session)
        ->toHaveKey('session_id')
        ->toHaveKey('status')
        ->toHaveKey('decision');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://verification.didit.me/v3/session/test-session-id/decision/' &&
            $request->method() === 'GET' &&
            $request->header('x-api-key')[0] === 'test-api-key';
    });
});

it('updates session status', function () {
    Http::fake([
        'verification.didit.me/v3/session/*/update-status*' => Http::response([
            'session_id' => 'test-session-id',
            'status' => 'Approved',
        ]),
    ]);

    $result = $this->client->updateSessionStatus('test-session-id', 'Approved', 'Test comment');

    expect($result)
        ->toHaveKey('session_id')
        ->toHaveKey('status');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://verification.didit.me/v3/session/test-session-id/update-status/' &&
            $request->method() === 'PATCH' &&
            $request['new_status'] === 'Approved' &&
            $request['comment'] === 'Test comment';
    });
});

it('rejects an invalid status', function () {
    expect(fn () => $this->client->updateSessionStatus('test-session-id', 'Bogus'))
        ->toThrow(Exception::class, 'newStatus must be one of');
});

it('preserves string zero identifiers and comments', function () {
    Http::fake(['*' => Http::response(['session_id' => 'test-session-id'])]);

    $this->client->createSession(vendorData: '0', options: ['workflow_id' => 'workflow-id']);
    $this->client->updateSessionStatus('test-session-id', 'Approved', '0');

    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['vendor_data'] === '0');
    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['comment'] === '0');
});

it('encodes session identifiers as a single path segment', function () {
    Http::fake(['*' => Http::response(['session_id' => 'test'])]);

    $this->client->getSession('folder/id?query=value');

    Http::assertSent(fn ($request) => $request->url() === 'https://verification.didit.me/v3/session/folder%2Fid%3Fquery%3Dvalue/decision/');
});

it('rejects malformed successful JSON responses without losing the response', function (string $body) {
    Http::fake(['*' => Http::response($body, 200)]);

    try {
        $this->client->getSession('test-session-id');
        $this->fail('Expected an invalid response exception.');
    } catch (DiditRequestException $exception) {
        expect($exception->status())->toBe(200)
            ->and($exception->getPrevious())->toBeInstanceOf(JsonException::class);
    }
})->with(['', '<html>Gateway error</html>', '[]', 'null']);

it('rejects redirects instead of forwarding API credentials', function () {
    Http::fake(['verification.didit.me/*' => Http::response('', 302, ['Location' => 'https://other-host.invalid/'])]);

    try {
        $this->client->getSession('test-session-id');
        $this->fail('Expected a redirect response to be rejected.');
    } catch (DiditRequestException $exception) {
        expect($exception->status())->toBe(302);
    }

    Http::assertSentCount(1);
});
