<?php

declare(strict_types=1);

namespace AwaisJameel\DiditLaravelClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string getAccessToken()
 * @method static array createSession(?string $callbackUrl = null, ?string $vendorData = null, array $options = [])
 * @method static string generateSessionPDF(string $sessionId)
 * @method static array updateSessionStatus(string $sessionId, string $newStatus, ?string $comment = null, array $options = [])
 * @method static array getSession(string $sessionId)
 * @method static array verifyWebhookSignature(array $headers, string $rawBody)
 * @method static array processWebhook(\Illuminate\Http\Request $request)
 *
 * @see \AwaisJameel\DiditLaravelClient\DiditLaravelClient
 */
class DiditLaravelClient extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AwaisJameel\DiditLaravelClient\DiditLaravelClient::class;
    }
}
