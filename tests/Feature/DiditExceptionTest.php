<?php

use AwaisJameel\DiditLaravelClient\DiditLaravelClient;
use AwaisJameel\DiditLaravelClient\Exceptions\DiditConfigurationException;
use AwaisJameel\DiditLaravelClient\Exceptions\DiditException;
use AwaisJameel\DiditLaravelClient\Exceptions\DiditRequestException;
use Illuminate\Support\Facades\Http;

it('throws a configuration exception when credentials are missing', function () {
    expect(fn () => new DiditLaravelClient([
        'api_key' => '',
        'client_id' => '',
        'client_secret' => '',
    ]))->toThrow(DiditConfigurationException::class, 'DIDIT_API_KEY is required');
});

it('throws an invalid argument exception for an unknown status', function () {
    $client = new DiditLaravelClient([
        'api_key' => 'test-api-key',
        'base_url' => 'https://verification.didit.me',
    ]);

    expect(fn () => $client->updateSessionStatus('session-id', 'Bogus'))
        ->toThrow(InvalidArgumentException::class, 'newStatus must be one of');
});

it('throws a request exception carrying the HTTP response on API failure', function () {
    Http::fake([
        'verification.didit.me/v3/session/*/decision*' => Http::response(['detail' => 'not found'], 404),
    ]);

    $client = new DiditLaravelClient([
        'api_key' => 'test-api-key',
        'base_url' => 'https://verification.didit.me',
    ]);

    try {
        $client->getSession('missing-session');
        $this->fail('Expected a DiditRequestException to be thrown');
    } catch (DiditRequestException $exception) {
        expect($exception)->toBeInstanceOf(DiditException::class)
            ->and($exception->status())->toBe(404)
            ->and($exception->getResponse())->not->toBeNull();
    }
});
