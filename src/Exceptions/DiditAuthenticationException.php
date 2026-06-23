<?php

declare(strict_types=1);

namespace AwaisJameel\DiditLaravelClient\Exceptions;

/**
 * Thrown when the legacy OAuth2 client_credentials flow fails to obtain an
 * access token from the DiDiT auth server.
 */
class DiditAuthenticationException extends DiditException {}
