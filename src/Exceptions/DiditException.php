<?php

declare(strict_types=1);

namespace AwaisJameel\DiditLaravelClient\Exceptions;

use Exception;
use Illuminate\Http\Client\Response;

/**
 * Base exception for every error thrown by the DiDiT client.
 *
 * Catch this to handle any DiDiT-related failure in one place; catch a more
 * specific subclass when you need to react differently to configuration,
 * authentication, API request, or webhook problems.
 */
class DiditException extends Exception
{
    /**
     * The HTTP response associated with the error, when one is available
     * (e.g. for failed API requests).
     */
    protected ?Response $response = null;

    /**
     * Attach the HTTP response that triggered this exception.
     */
    public function setResponse(?Response $response): static
    {
        $this->response = $response;

        return $this;
    }

    /**
     * The HTTP response associated with the error, if any.
     */
    public function getResponse(): ?Response
    {
        return $this->response;
    }

    /**
     * The HTTP status code of the associated response, if any.
     */
    public function status(): ?int
    {
        return $this->response?->status();
    }
}
