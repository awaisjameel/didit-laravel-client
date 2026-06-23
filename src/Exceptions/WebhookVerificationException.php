<?php

declare(strict_types=1);

namespace AwaisJameel\DiditLaravelClient\Exceptions;

/**
 * Thrown when a webhook cannot be verified: missing/empty body, malformed
 * payload, stale timestamp, or a signature that does not match any of the
 * schemes DiDiT sends.
 */
class WebhookVerificationException extends DiditException {}
