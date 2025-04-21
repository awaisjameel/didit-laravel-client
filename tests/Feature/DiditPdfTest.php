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

    Http::fake([
        'apx.didit.me/auth/v2/token/*' => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 3600,
        ]),
    ]);
});

it('generates session PDF reports', function () {
    $pdfContent = 'PDF-CONTENT';
    Http::fake([
        'verification.didit.me/v1/session/*/generate-pdf*' => Http::response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $pdf = $this->client->generateSessionPDF('test-session-id');

    expect($pdf)->toBe($pdfContent);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://verification.didit.me/v1/session/test-session-id/generate-pdf/' &&
            $request->method() === 'GET' &&
            $request->header('Accept')[0] === 'application/pdf';
    });
});

it('handles PDF generation errors', function () {
    Http::fake([
        'verification.didit.me/v1/session/*/generate-pdf*' => Http::response(null, 404),
    ]);

    expect(fn() => $this->client->generateSessionPDF('invalid-session-id'))
        ->toThrow(Exception::class);
});