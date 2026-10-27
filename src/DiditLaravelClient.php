<?php

declare(strict_types=1);

namespace AwaisJameel\DiditLaravelClient;

use AwaisJameel\DiditLaravelClient\Events\DiditWebhookReceived;
use AwaisJameel\DiditLaravelClient\Exceptions\DiditConfigurationException;
use AwaisJameel\DiditLaravelClient\Exceptions\DiditException;
use AwaisJameel\DiditLaravelClient\Exceptions\DiditRequestException;
use AwaisJameel\DiditLaravelClient\Exceptions\WebhookVerificationException;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use stdClass;

class DiditLaravelClient
{
    protected string $apiKey;

    protected ?string $workflowId;

    protected string $baseUrl;

    protected string $apiVersion;

    protected ?string $webhookSecret;

    protected int $timeout;

    protected bool $debug;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        $this->apiKey = trim($this->stringConfig($config, 'api_key') ?? '');
        $this->workflowId = $this->stringConfig($config, 'workflow_id');
        $this->baseUrl = rtrim($this->stringConfig($config, 'base_url', 'https://verification.didit.me') ?? '', '/');
        $this->apiVersion = $this->stringConfig($config, 'api_version', 'v3') ?? '';
        $this->webhookSecret = $this->stringConfig($config, 'webhook_secret');

        $timeout = $config['timeout'] ?? config('didit-laravel-client.timeout', 10);
        $parsedTimeout = is_int($timeout) || is_string($timeout) ? filter_var($timeout, FILTER_VALIDATE_INT) : false;
        if ($parsedTimeout === false || $parsedTimeout < 1) {
            throw new DiditConfigurationException('DIDIT_TIMEOUT must be a positive integer');
        }
        $this->timeout = $parsedTimeout;

        $debug = filter_var($config['debug'] ?? config('didit-laravel-client.debug', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($debug === null) {
            throw new DiditConfigurationException('DIDIT_DEBUG must be a boolean');
        }
        $this->debug = $debug;
        $this->validateConfig();
    }

    /** @param array<string, mixed> $config */
    protected function stringConfig(array $config, string $key, ?string $default = null): ?string
    {
        $value = $config[$key] ?? config('didit-laravel-client.'.$key, $default);
        if ($value !== null && ! is_string($value)) {
            throw new DiditConfigurationException("The {$key} configuration value must be a string");
        }

        return $value;
    }

    protected function validateConfig(): void
    {
        if ($this->apiKey === '') {
            throw new DiditConfigurationException('DIDIT_API_KEY is required; legacy OAuth2 authentication is no longer supported');
        }
        $url = parse_url($this->baseUrl);
        if (! is_array($url) || empty($url['host']) || ! in_array($url['scheme'] ?? '', ['http', 'https'], true) || isset($url['query']) || isset($url['fragment'])) {
            throw new DiditConfigurationException('DIDIT_BASE_URL must be an HTTP(S) base URL without a query or fragment');
        }
        if (preg_match('/\Av[0-9]+\z/', $this->apiVersion) !== 1) {
            throw new DiditConfigurationException('DIDIT_API_VERSION must be a version segment such as v3');
        }
    }

    protected function endpoint(string $path): string
    {
        return $this->baseUrl."/{$this->apiVersion}/{$path}";
    }

    /** @param array<string, mixed> $options */
    protected function createHttpClient(array $options = []): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withOptions($options)
            ->withoutRedirecting()
            ->withHeaders(['x-api-key' => $this->apiKey]);
    }

    protected function log(string $message, mixed $data = null): void
    {
        if ($this->debug) {
            Log::debug("[Didit] {$message}", $data === null ? [] : ['data' => $data]);
        }
    }

    protected function handleError(Exception $exception, string $context): DiditException
    {
        if ($exception instanceof DiditException) {
            $this->log("{$context} failed", ['exception' => $exception::class, 'status' => $exception->status()]);

            return $exception;
        }
        $response = $exception instanceof RequestException ? $exception->response : null;
        $this->log("{$context} failed", ['exception' => $exception::class, 'status' => $response?->status()]);

        return (new DiditRequestException("Didit {$context} failed: {$exception->getMessage()}", (int) $exception->getCode(), $exception))
            ->setResponse($response);
    }

    protected function ensureSuccessful(Response $response): void
    {
        if (! $response->successful()) {
            throw (new DiditRequestException('Didit returned HTTP '.$response->status(), $response->status()))
                ->setResponse($response);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function makeAuthenticatedRequest(string $method, string $url, array $options = [], string $context = 'API request'): array
    {
        try {
            $request = $this->createHttpClient()->acceptJson()->asJson();
            if (isset($options['headers'])) {
                if (! is_array($options['headers'])) {
                    throw new InvalidArgumentException('Request headers must be an array');
                }
                $request->withHeaders($options['headers']);
                unset($options['headers']);
            }
            $response = $request->$method($url, $options);
            $this->ensureSuccessful($response);

            try {
                return $this->decodeObject($response->body())['payload'];
            } catch (JsonException $exception) {
                throw (new DiditRequestException('Didit returned an invalid JSON object', previous: $exception))->setResponse($response);
            }
        } catch (Exception $exception) {
            throw $this->handleError($exception, $context);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function createSession(?string $callbackUrl = null, ?string $vendorData = null, array $options = []): array
    {
        $workflowId = $options['workflow_id'] ?? $this->workflowId;
        if (! is_string($workflowId) || trim($workflowId) === '') {
            throw new DiditConfigurationException('workflow_id is required (pass it in $options or set DIDIT_WORKFLOW_ID)');
        }
        $data = array_merge($options, ['workflow_id' => $workflowId]);
        if ($callbackUrl !== null) {
            $data['callback'] = $callbackUrl;
        }
        if ($vendorData !== null) {
            $data['vendor_data'] = $vendorData;
        }

        return $this->makeAuthenticatedRequest('post', $this->endpoint('session/'), $data, 'session creation');
    }

    public function generateSessionPDF(string $sessionId): string
    {
        $id = $this->sessionId($sessionId);
        try {
            $response = $this->createHttpClient()->withHeaders(['Accept' => 'application/pdf'])
                ->get($this->endpoint("session/{$id}/generate-pdf/"));
            $this->ensureSuccessful($response);
            if (! str_starts_with($response->body(), '%PDF')) {
                throw (new DiditRequestException('Didit returned an invalid PDF document'))->setResponse($response);
            }

            return $response->body();
        } catch (Exception $exception) {
            throw $this->handleError($exception, 'PDF generation');
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function updateSessionStatus(string $sessionId, string $newStatus, ?string $comment = null, array $options = []): array
    {
        $id = $this->sessionId($sessionId);
        if (! in_array($newStatus, ['Approved', 'Declined', 'Resubmitted'], true)) {
            throw new InvalidArgumentException('newStatus must be one of "Approved", "Declined" or "Resubmitted"');
        }
        $data = array_merge($options, ['new_status' => $newStatus]);
        if ($comment !== null) {
            $data['comment'] = $comment;
        }

        return $this->makeAuthenticatedRequest('patch', $this->endpoint("session/{$id}/update-status/"), $data, 'status update');
    }

    /** @return array<string, mixed> */
    public function getSession(string $sessionId): array
    {
        return $this->makeAuthenticatedRequest('get', $this->endpoint('session/'.$this->sessionId($sessionId).'/decision/'), [], 'session retrieval');
    }

    protected function sessionId(string $sessionId): string
    {
        if (trim($sessionId) === '') {
            throw new InvalidArgumentException('sessionId is required');
        }

        return rawurlencode($sessionId);
    }

    /**
     * Accept full-payload signatures only. Freshness comes from signed body data.
     *
     * @param  array<string, string|int|null>  $headers
     * @return array<string, mixed>
     */
    public function verifyWebhookSignature(array $headers, string $rawBody): array
    {
        if ($this->webhookSecret === null || $this->webhookSecret === '') {
            throw new DiditConfigurationException('DIDIT_WEBHOOK_SECRET is required for webhook verification');
        }
        if ($rawBody === '') {
            throw new WebhookVerificationException('Missing required webhook verification data');
        }
        $headers = $this->normalizeHeaders($headers);
        try {
            $decoded = $this->decodeObject($rawBody);
            $payload = $decoded['payload'];
            $timestamp = array_key_exists('timestamp', $payload)
                ? $this->unixTimestamp($payload['timestamp'])
                : $this->legacyTimestamp($payload['created_at'] ?? null);
            $now = Carbon::now()->getTimestamp();
            if ($timestamp === null || abs($now - $timestamp) > 300) {
                throw new WebhookVerificationException('Request timestamp is stale or invalid');
            }
            if (isset($headers['x-timestamp'])) {
                $headerTimestamp = $this->unixTimestamp($headers['x-timestamp']);
                if ($headerTimestamp === null || abs($now - $headerTimestamp) > 300) {
                    throw new WebhookVerificationException('Request timestamp is stale or invalid');
                }
                if ($headerTimestamp !== $timestamp) {
                    throw new WebhookVerificationException('Timestamp header does not match the signed payload');
                }
            }

            $canonical = json_encode($this->canonicalizeWebhookPayload($decoded['object']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
            if ($this->matchesHeader($headers, 'x-signature-v2', $canonical) || $this->matchesHeader($headers, 'x-signature', $rawBody)) {
                return $payload;
            }
        } catch (JsonException $exception) {
            throw new WebhookVerificationException('Invalid webhook payload', previous: $exception);
        }

        throw new WebhookVerificationException('Invalid webhook signature; a full-payload V2 or raw-body signature is required');
    }

    /**
     * @param  array<string, string|int|null>  $headers
     * @return array<string, string>
     */
    protected function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if ($value === null) {
                continue;
            }
            $key = strtolower($name);
            $text = (string) $value;
            if (isset($normalized[$key]) && $normalized[$key] !== $text) {
                throw new WebhookVerificationException('Ambiguous webhook header: '.$key);
            }
            $normalized[$key] = $text;
        }

        return $normalized;
    }

    /** @param array<string, string> $headers */
    protected function matchesHeader(array $headers, string $name, string $message): bool
    {
        foreach ([$name, $name.'-previous'] as $header) {
            $signature = $headers[$header] ?? '';
            if (preg_match('/\A[a-f0-9]{64}\z/i', $signature) === 1 && $this->signatureMatches($message, strtolower($signature))) {
                return true;
            }
        }

        return false;
    }

    protected function signatureMatches(string $message, string $providedSignature): bool
    {
        return hash_equals(hash_hmac('sha256', $message, $this->webhookSecret ?? ''), $providedSignature);
    }

    protected function unixTimestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value) || preg_match('/\A[0-9]+\z/', $value) !== 1) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($parsed) ? $parsed : null;
    }

    protected function legacyTimestamp(mixed $value): ?int
    {
        $unix = $this->unixTimestamp($value);
        if ($unix !== null) {
            return $unix;
        }
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/', $value) !== 1) {
            return null;
        }
        $parsed = strtotime($value);

        return $parsed === false ? null : $parsed;
    }

    /** @return array{object: stdClass, payload: array<string, mixed>} */
    protected function decodeObject(string $body): array
    {
        $object = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        $values = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (! $object instanceof stdClass || ! is_array($values)) {
            throw new JsonException('Expected a JSON object');
        }

        return ['object' => $object, 'payload' => $values];
    }

    protected function canonicalizeWebhookPayload(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $result = new stdClass;
            foreach ($properties as $key => $item) {
                $result->{(string) $key} = $this->canonicalizeWebhookPayload($item);
            }

            return $result;
        }
        if (is_array($value)) {
            return array_map($this->canonicalizeWebhookPayload(...), $value);
        }
        if (is_float($value) && $value === (float) (int) $value) {
            return (int) $value;
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function processWebhook(Request $request): array
    {
        $headers = [];
        foreach (['x-signature-v2', 'x-signature-v2-previous', 'x-signature', 'x-signature-previous', 'x-timestamp'] as $name) {
            $headers[$name] = $request->headers->get($name);
        }
        $body = $request->getContent();
        $payload = $this->verifyWebhookSignature($headers, $body);
        $this->log('Webhook verified', ['webhook_type' => $payload['webhook_type'] ?? null]);
        Event::dispatch(new DiditWebhookReceived($payload));

        return $payload;
    }
}
