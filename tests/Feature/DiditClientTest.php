<?php

use AwaisJameel\DiditLaravelClient\DiditLaravelClient;
use AwaisJameel\DiditLaravelClient\Exceptions\DiditConfigurationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('requires an API key even when legacy credentials are supplied', function () {
    expect(fn () => new DiditLaravelClient([
        'api_key' => '',
        'client_id' => 'legacy-client',
        'client_secret' => 'legacy-secret',
    ]))->toThrow(DiditConfigurationException::class, 'DIDIT_API_KEY is required');

    Http::assertNothingSent();
});

it('sends API key authentication without an OAuth request or bearer token', function () {
    Http::fake(['verification.didit.me/v3/session/*/decision*' => Http::response(['session_id' => 'x'])]);

    $client = new DiditLaravelClient(['api_key' => 'test-api-key']);
    $client->getSession('test-session-id');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->header('x-api-key')[0] === 'test-api-key'
        && ! $request->hasHeader('Authorization'));
});

it('rejects invalid configuration with a package exception', function (array $config) {
    expect(fn () => new DiditLaravelClient($config))->toThrow(DiditConfigurationException::class);
})->with([
    'non-string API key' => [['api_key' => 123]],
    'empty API key' => [['api_key' => '   ']],
    'zero timeout' => [['timeout' => 0]],
    'invalid timeout' => [['timeout' => 'many']],
    'invalid boolean' => [['debug' => 'sometimes']],
    'empty URL' => [['base_url' => '']],
    'invalid version' => [['api_version' => '../v3']],
]);
