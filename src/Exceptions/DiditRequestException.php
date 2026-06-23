<?php

declare(strict_types=1);

namespace AwaisJameel\DiditLaravelClient\Exceptions;

/**
 * Thrown when an authenticated DiDiT API request fails (non-2xx response or a
 * transport error). Use getResponse()/status() to inspect the HTTP response
 * when one is available.
 */
class DiditRequestException extends DiditException {}
