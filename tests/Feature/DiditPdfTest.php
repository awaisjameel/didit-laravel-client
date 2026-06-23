<?php

use AwaisJameel\DiditLaravelClient\DiditLaravelClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->client = new DiditLaravelClient([
        'api_key' => 'test-api-key',
        'base_url' => 'https://verification.didit.me',
    ]);
});

it('generates session PDF reports', function () {
    $pdfContent = 'PDF-CONTENT';
    Http::fake([
        'verification.didit.me/v3/session/*/generate-pdf*' => Http::response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $pdf = $this->client->generateSessionPDF('test-session-id');

    expect($pdf)->toBe($pdfContent);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://verification.didit.me/v3/session/test-session-id/generate-pdf' &&
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
