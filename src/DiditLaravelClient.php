<?php

declare(strict_types=1);

namespace AwaisJameel\DiditLaravelClient;

use AwaisJameel\DiditLaravelClient\Events\DiditWebhookReceived;
use AwaisJameel\DiditLaravelClient\Exceptions\DiditAuthenticationException;
use AwaisJameel\DiditLaravelClient\Exceptions\DiditConfigurationException;
use AwaisJameel\DiditLaravelClient\Exceptions\DiditException;
use AwaisJameel\DiditLaravelClient\Exceptions\DiditRequestException;
use AwaisJameel\DiditLaravelClient\Exceptions\WebhookVerificationException;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class DiditLaravelClient
{
    /** Preferred auth: sent as the "x-api-key" header. */
    protected ?string $apiKey = null;

    /** Legacy OAuth2 fallback, used only when no API key is set. */
    protected ?string $clientId = null;

    protected ?string $clientSecret = null;

    protected ?string $workflowId = null;

    protected ?string $baseUrl = null;

    /** API version segment used to build endpoints, e.g. "v3". */
    protected string $apiVersion = 'v3';

    protected ?string $authUrl = null;

    protected ?string $webhookSecret = null;

    /** Seconds to subtract from a token's expiry before treating it as expired. */
    protected int $tokenExpiryBuffer;

    protected int $timeout;

    protected bool $debug;

    /**
     * Create a new DiDiT client instance
     *
     * @param  array<string, mixed>  $config  Client configuration
     */
    public function __construct(array $config = [])
    {
        // Values fall back to the package config, which reads the environment
        // (keeping env() calls inside the config file is config-cache safe).
        $this->apiKey = $config['api_key'] ?? config('didit-laravel-client.api_key');

        $this->clientId = $config['client_id'] ?? config('didit-laravel-client.client_id');
        $this->clientSecret = $config['client_secret'] ?? config('didit-laravel-client.client_secret');

        $this->baseUrl = $config['base_url'] ?? config('didit-laravel-client.base_url', 'https://verification.didit.me');
        $this->authUrl = $config['auth_url'] ?? config('didit-laravel-client.auth_url', 'https://apx.didit.me');
        $this->apiVersion = $config['api_version'] ?? config('didit-laravel-client.api_version', 'v3');

        $this->workflowId = $config['workflow_id'] ?? config('didit-laravel-client.workflow_id');

        // Numeric/boolean values are cast explicitly because env() returns them
        // as strings, which strict typing would reject.
        $this->webhookSecret = $config['webhook_secret'] ?? config('didit-laravel-client.webhook_secret');
        $this->timeout = (int) ($config['timeout'] ?? config('didit-laravel-client.timeout', 10));
        $this->tokenExpiryBuffer = (int) ($config['token_expiry_buffer'] ?? config('didit-laravel-client.token_expiry_buffer', 300));
        $this->debug = (bool) ($config['debug'] ?? config('didit-laravel-client.debug', false));

        $this->validateConfig();
    }

    /**
     * Validate required configuration
     *
     * @throws DiditConfigurationException
     */
    protected function validateConfig(): void
    {
        if (empty($this->baseUrl)) {
            throw new DiditConfigurationException('DIDIT_BASE_URL is required');
        }

        // The preferred path is an API key. When it is absent we fall back to the
        // legacy OAuth2 client_credentials flow, which needs both credentials and
        // the auth URL.
        if (empty($this->apiKey)) {
            if (empty($this->clientId) || empty($this->clientSecret)) {
                throw new DiditConfigurationException('DIDIT_API_KEY is required (or DIDIT_CLIENT_ID and DIDIT_CLIENT_SECRET for legacy OAuth2)');
            }

            if (empty($this->authUrl)) {
                throw new DiditConfigurationException('DIDIT_AUTH_URL is required');
            }
        }
    }

    /**
     * Whether the client is configured to use API key authentication.
     */
    protected function usesApiKey(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Build a full verification API endpoint URL for the configured version.
     *
     * @param  string  $path  Path after the version segment (e.g. "session/")
     */
    protected function endpoint(string $path): string
    {
        // Trim a trailing slash off the base URL so a configured value with or
        // without one (e.g. "https://verification.didit.me/") never produces a
        // double slash in the final endpoint.
        return rtrim($this->baseUrl, '/')."/{$this->apiVersion}/{$path}";
    }

    /**
     * Resolve the authentication headers for an API request.
     *
     * Prefers the "x-api-key" header; falls back to a legacy OAuth2 bearer token.
     *
     * @return array<string, string>
     *
     * @throws Exception If OAuth authentication fails
     */
    protected function authHeaders(): array
    {
        if ($this->usesApiKey()) {
            return ['x-api-key' => $this->apiKey];
        }

        return ['Authorization' => 'Bearer '.$this->getAccessToken()];
    }

    /**
     * Create an HTTP client instance with common configuration
     *
     * @param  array  $options  Additional options
     */
    protected function createHttpClient(array $options = []): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withOptions($options);
    }

    /**
     * Log debug messages if debug mode is enabled
     *
     * @param  string  $message  Debug message
     * @param  mixed  $data  Optional data to log
     */
    protected function log(string $message, $data = null): void
    {
        if ($this->debug) {
            Log::debug("[DiDiT] {$message}", $data ? ['data' => $data] : []);
        }
    }

    /**
     * Log an error and wrap it in a DiDiT exception of the given type, carrying
     * the HTTP response when one is available.
     *
     * @param  Exception  $exception  Error object
     * @param  string  $context  Context where the error occurred
     * @param  class-string<DiditException>  $exceptionClass  The DiDiT exception type to return
     * @return DiditException Formatted error
     */
    protected function handleError(
        Exception $exception,
        string $context,
        string $exceptionClass = DiditRequestException::class
    ): DiditException {
        // A DiDiT exception raised deeper in the stack (e.g. an authentication
        // failure while resolving headers) is already well-typed; pass it
        // through rather than re-wrapping and losing its specific type.
        if ($exception instanceof DiditException) {
            return $exception;
        }

        $errorMessage = "DiDit Error in {$context}: {$exception->getMessage()}";

        $response = $exception instanceof RequestException ? $exception->response : null;

        if ($response !== null) {
            $this->log($errorMessage, $response->json() ?? []);
        } else {
            $this->log($errorMessage, [
                'code' => $exception->getCode(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }

        /** @var DiditException $diditException */
        $diditException = new $exceptionClass($errorMessage, (int) $exception->getCode(), $exception);

        return $diditException->setResponse($response);
    }

    /**
     * The cache key under which the legacy OAuth access token is stored.
     *
     * Scoped to the credentials/auth URL so distinct configurations never share
     * a token.
     */
    protected function tokenCacheKey(): string
    {
        return 'didit-laravel-client:oauth-token:'.sha1("{$this->authUrl}|{$this->clientId}");
    }

    /**
     * Get an access token, either from the cache or by requesting a new one.
     *
     * The token is cached through Laravel's cache store so it is shared across
     * requests and workers, rather than being re-fetched on every request.
     *
     * @return string The access token
     *
     * @throws DiditAuthenticationException If authentication fails
     */
    public function getAccessToken(): string
    {
        $cacheKey = $this->tokenCacheKey();

        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            $this->log('Using cached access token');

            return $cachedToken;
        }

        $this->log('Fetching new access token');

        try {
            $url = "{$this->authUrl}/auth/v2/token/";
            $encodedCredentials = base64_encode("{$this->clientId}:{$this->clientSecret}");

            $response = $this->createHttpClient()
                ->withHeaders([
                    'Authorization' => "Basic {$encodedCredentials}",
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ])
                ->asForm()
                ->post($url, [
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->failed()) {
                $response->throw();
            }

            $data = $response->json();

            if (! isset($data['access_token'])) {
                throw new DiditAuthenticationException('Invalid response from auth server');
            }

            $token = (string) $data['access_token'];
            $expiresIn = (int) ($data['expires_in'] ?? 3600);

            // Cache the token, expiring it early by the configured buffer so it is
            // never served too close to its real expiry. Keep at least 1 second.
            Cache::put($cacheKey, $token, max(1, $expiresIn - $this->tokenExpiryBuffer));

            return $token;
        } catch (Exception $exception) {
            throw $this->handleError($exception, 'authentication', DiditAuthenticationException::class);
        }
    }

    /**
     * Make an authenticated API request to DiDiT
     *
     * @param  string  $method  HTTP method
     * @param  string  $url  Request URL
     * @param  array<string, mixed>  $options  Request options
     * @param  string  $context  Context for error handling
     * @return array<string, mixed> API response decoded from JSON
     *
     * @throws DiditException If the request fails
     */
    protected function makeAuthenticatedRequest(
        string $method,
        string $url,
        array $options = [],
        string $context = 'API request'
    ): array {
        try {
            $request = $this->createHttpClient()
                ->withHeaders(array_merge($this->authHeaders(), [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]));

            if (isset($options['headers'])) {
                $request->withHeaders($options['headers']);
                unset($options['headers']);
            }

            $response = $request->$method($url, $options);

            if ($response->failed()) {
                $response->throw();
            }

            $json = $response->json();

            // A successful response with an empty or non-JSON body decodes to
            // null; normalize to an array so the declared return type holds.
            /** @var array<string, mixed> $data */
            $data = is_array($json) ? $json : [];

            return $data;
        } catch (Exception $exception) {
            throw $this->handleError($exception, $context);
        }
    }

    /**
     * Create a new verification session
     *
     * The Didit v3 API requires a `workflow_id` (configured in the business
     * console). It is resolved from `$options['workflow_id']`, falling back to
     * the configured default (DIDIT_WORKFLOW_ID).
     *
     * @param  string|null  $callbackUrl  Optional URL to redirect to after verification
     * @param  string|null  $vendorData  Optional unique identifier for the vendor, typically the user being verified
     * @param  array<string, mixed>  $options  Additional session options: workflow_id, callback_method,
     *                                         metadata, contact_details, expected_details, etc.
     * @return array<string, mixed> Session data (session_id, url, ...)
     *
     * @throws DiditConfigurationException If no workflow_id is available
     * @throws DiditException If session creation fails
     */
    public function createSession(
        ?string $callbackUrl = null,
        ?string $vendorData = null,
        array $options = []
    ): array {
        $workflowId = $options['workflow_id'] ?? $this->workflowId;

        if (empty($workflowId)) {
            throw new DiditConfigurationException('workflow_id is required (pass it in $options or set DIDIT_WORKFLOW_ID)');
        }

        $data = array_merge($options, ['workflow_id' => $workflowId]);

        if ($callbackUrl) {
            $data['callback'] = $callbackUrl;
        }

        if ($vendorData) {
            $data['vendor_data'] = $vendorData;
        }

        return $this->makeAuthenticatedRequest(
            'post',
            $this->endpoint('session/'),
            $data,
            'session creation'
        );
    }

    /**
     * Generate a PDF report for a session
     *
     * @param  string  $sessionId  The ID of the session
     * @return string PDF content
     *
     * @throws InvalidArgumentException If the session id is empty
     * @throws DiditException If PDF generation fails
     */
    public function generateSessionPDF(string $sessionId): string
    {
        if (empty($sessionId)) {
            throw new InvalidArgumentException('sessionId is required');
        }

        $url = $this->endpoint("session/{$sessionId}/generate-pdf");

        try {
            $response = $this->createHttpClient()
                ->withHeaders(array_merge($this->authHeaders(), [
                    'Accept' => 'application/pdf',
                ]))
                ->get($url);

            if ($response->failed()) {
                $response->throw();
            }

            return $response->body();
        } catch (Exception $exception) {
            throw $this->handleError($exception, 'PDF generation');
        }
    }

    /**
     * Update the status of a session
     *
     * @param  string  $sessionId  The ID of the session
     * @param  string  $newStatus  The new status ('Approved', 'Declined' or 'Resubmitted')
     * @param  string|null  $comment  Optional comment
     * @param  array<string, mixed>  $options  Additional fields: send_email, email_address, email_language, nodes_to_resubmit
     * @return array<string, mixed> Updated session data
     *
     * @throws InvalidArgumentException If the session id is empty or the status is invalid
     * @throws DiditException If status update fails
     */
    public function updateSessionStatus(
        string $sessionId,
        string $newStatus,
        ?string $comment = null,
        array $options = []
    ): array {
        if (empty($sessionId)) {
            throw new InvalidArgumentException('sessionId is required');
        }

        if (! in_array($newStatus, ['Approved', 'Declined', 'Resubmitted'])) {
            throw new InvalidArgumentException('newStatus must be one of "Approved", "Declined" or "Resubmitted"');
        }

        $data = array_merge($options, ['new_status' => $newStatus]);

        if ($comment) {
            $data['comment'] = $comment;
        }

        return $this->makeAuthenticatedRequest(
            'patch',
            $this->endpoint("session/{$sessionId}/update-status/"),
            $data,
            'status update'
        );
    }

    /**
     * Retrieve details of an existing session
     *
     * @param  string  $sessionId  The ID of the session
     * @return array<string, mixed> Session data
     *
     * @throws InvalidArgumentException If the session id is empty
     * @throws DiditException If session retrieval fails
     */
    public function getSession(string $sessionId): array
    {
        if (empty($sessionId)) {
            throw new InvalidArgumentException('sessionId is required');
        }

        return $this->makeAuthenticatedRequest(
            'get',
            $this->endpoint("session/{$sessionId}/decision/"),
            [],
            'session retrieval'
        );
    }

    /**
     * Verify the signature of a webhook payload.
     *
     * Didit sends up to three signatures per webhook. They are tried in order of
     * preference until one matches:
     *   1. x-signature-v2     — HMAC of "{timestamp}:{canonical sorted-key JSON}" (recommended)
     *   2. x-signature-simple — HMAC of "{timestamp}:{session_id}:{status}:{webhook_type}"
     *   3. x-signature        — HMAC of the raw request body (legacy)
     *
     * @param  array<string, string|null>  $headers  The headers from the webhook request (lowercase keys)
     * @param  string  $rawBody  The raw body of the webhook request
     * @return array<string, mixed> The parsed webhook event data
     *
     * @throws DiditConfigurationException If no webhook secret is configured
     * @throws WebhookVerificationException If signature verification fails
     */
    public function verifyWebhookSignature(array $headers, string $rawBody): array
    {
        if (empty($this->webhookSecret)) {
            throw new DiditConfigurationException('DIDIT_WEBHOOK_SECRET is required for webhook verification');
        }

        if (empty($rawBody)) {
            throw new WebhookVerificationException('Missing required webhook verification data');
        }

        $signature = $headers['x-signature'] ?? null;
        $signatureV2 = $headers['x-signature-v2'] ?? null;
        $signatureSimple = $headers['x-signature-simple'] ?? null;

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            throw new WebhookVerificationException('Invalid webhook payload');
        }

        /** @var array<string, mixed> $payload */

        // The timestamp comes from the x-timestamp header, falling back to the
        // body ("timestamp" or legacy "created_at").
        $timestamp = $headers['x-timestamp']
            ?? ($payload['timestamp'] ?? ($payload['created_at'] ?? null));

        if ($timestamp === null || (! $signature && ! $signatureV2 && ! $signatureSimple)) {
            throw new WebhookVerificationException('Missing required webhook verification data');
        }

        // Validate the timestamp to ensure the request is fresh (within 5 minutes).
        // Didit sends a Unix timestamp, but the legacy "created_at" body fallback
        // may be an ISO-8601 string, so parse non-numeric values with strtotime()
        // rather than letting an (int) cast silently truncate them to a bogus year.
        $freshnessTs = is_numeric($timestamp)
            ? (int) $timestamp
            : strtotime((string) $timestamp);

        if ($freshnessTs === false || abs(Carbon::now()->timestamp - $freshnessTs) > 300) {
            throw new WebhookVerificationException('Request timestamp is stale');
        }

        // 1. V2 signature (recommended): HMAC over the canonical, sorted-key JSON.
        //
        // Didit's own reference implementations disagree on whether the timestamp
        // is prefixed to the canonical body before hashing, so we accept either
        // form. Both still require the shared secret to forge, so accepting both
        // does not weaken verification.
        if ($signatureV2) {
            $canonical = json_encode(
                $this->canonicalizeWebhookPayload($payload),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            if (
                $this->signatureMatches("{$timestamp}:{$canonical}", $signatureV2) ||
                $this->signatureMatches($canonical, $signatureV2)
            ) {
                return $payload;
            }
        }

        // 2. Simple signature: built from a handful of key fields, immune to JSON re-encoding.
        if ($signatureSimple) {
            $message = implode(':', [
                (string) $timestamp,
                (string) ($payload['session_id'] ?? ''),
                (string) ($payload['status'] ?? ''),
                (string) ($payload['webhook_type'] ?? ''),
            ]);

            if ($this->signatureMatches($message, $signatureSimple)) {
                return $payload;
            }
        }

        // 3. Legacy signature: HMAC of the raw request body.
        if ($signature && $this->signatureMatches($rawBody, $signature)) {
            return $payload;
        }

        throw new WebhookVerificationException('Invalid webhook signature');
    }

    /**
     * Compare an HMAC-SHA256 of the given message against a provided signature
     * using a timing-attack-safe comparison.
     */
    protected function signatureMatches(string $message, string $providedSignature): bool
    {
        $expected = hash_hmac('sha256', $message, $this->webhookSecret);

        return hash_equals($expected, $providedSignature);
    }

    /**
     * Recursively normalize a decoded payload so its JSON encoding matches the
     * canonical form Didit signs: object keys sorted, and whole-number floats
     * collapsed to integers.
     *
     * @param  mixed  $value
     * @return mixed
     */
    protected function canonicalizeWebhookPayload($value)
    {
        if (is_array($value)) {
            // A JSON array (list) keeps its order; an object (assoc) is key-sorted.
            $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);

            if ($isList) {
                return array_map([$this, 'canonicalizeWebhookPayload'], $value);
            }

            // Sort by string (Unicode code point) to match Python's
            // json.dumps(sort_keys=True), which Didit uses to build the canonical
            // form. PHP's default SORT_REGULAR would order numeric-like keys
            // numerically and drift from Didit's signature.
            ksort($value, SORT_STRING);

            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->canonicalizeWebhookPayload($item);
            }

            return $result;
        }

        if (is_float($value) && $value === (float) (int) $value) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Process a webhook request by verifying its signature and parsing the payload.
     *
     * On success a DiditWebhookReceived event is dispatched so listeners can
     * react without inline handling.
     *
     * @param  Request  $request  The Laravel request object
     * @return array<string, mixed> The parsed and verified webhook event data
     *
     * @throws WebhookVerificationException If webhook processing fails
     */
    public function processWebhook(Request $request): array
    {
        $rawBody = $request->getContent();

        if (empty($rawBody)) {
            throw new WebhookVerificationException('Request content is empty');
        }

        // Laravel returns a single value per header here; collect all the
        // signature variants Didit may send.
        $webhookHeaders = [
            'x-signature' => $request->header('x-signature'),
            'x-signature-v2' => $request->header('x-signature-v2'),
            'x-signature-simple' => $request->header('x-signature-simple'),
            'x-timestamp' => $request->header('x-timestamp'),
        ];

        $payload = $this->verifyWebhookSignature($webhookHeaders, $rawBody);
        $this->log('Webhook verified and processed', $payload);

        Event::dispatch(new DiditWebhookReceived($payload));

        return $payload;
    }
}
