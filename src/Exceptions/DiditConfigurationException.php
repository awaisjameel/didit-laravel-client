<?php

declare(strict_types=1);

namespace AwaisJameel\DiditLaravelClient\Exceptions;

/**
 * Thrown when the client is missing required configuration, such as the API
 * key, OAuth credentials, base URL, workflow id, or webhook secret.
 */
class DiditConfigurationException extends DiditException {}
