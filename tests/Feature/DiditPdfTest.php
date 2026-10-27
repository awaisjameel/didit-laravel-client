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

it('generates session PDF reports', function () {
    $pdfContent = "%PDF-1.7\nTest PDF response";
    Http::fake([
        'verification.didit.me/v3/session/*/generate-pdf*' => Http::response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $pdf = $this->client->generateSessionPDF('test-session-id');

    expect($pdf)->toBe($pdfContent);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://verification.didit.me/v3/session/test-session-id/generate-pdf/' &&
            $request->method() === 'GET' &&
            $request->header('x-api-key')[0] === 'test-api-key' &&
            $request->header('Accept')[0] === 'application/pdf';
    });
});

it('handles PDF generation errors', function () {
    Http::fake([
        'verification.didit.me/v3/session/*/generate-pdf*' => Http::response(null, 404),
    ]);

    expect(fn () => $this->client->generateSessionPDF('invalid-session-id'))
        ->toThrow(Exception::class);
});

it('rejects successful responses that are not PDF documents', function () {
    Http::fake(['*' => Http::response('{"detail":"upstream error"}', 200)]);

    expect(fn () => $this->client->generateSessionPDF('test-session-id'))
        ->toThrow(DiditRequestException::class, 'invalid PDF');
});
