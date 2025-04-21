<?php

namespace AwaisJameel\DiditLaravelClient;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiditLaravelClient
{
    /**
     * Client ID for DiDiT API
     */
    protected ?string $clientId = null;

    /**
     * Client secret for DiDiT API
     */
    protected ?string $clientSecret = null;

    /**
     * Base URL for DiDiT API
     */
    protected ?string $baseUrl = null;

    /**
     * Auth URL for DiDiT API
     */
    protected ?string $authUrl = null;

    /**
     * Secret for webhook verification
     */
    protected ?string $webhookSecret = null;

    /**
     * Buffer time before token expiry (in seconds)
     */
    protected int $tokenExpiryBuffer;

    /**
     * Request timeout (in seconds)
     */
    protected int $timeout;

    /**
     * Debug mode flag
     */
    protected bool $debug;

    /**
     * Access token cache
     *
     * @var array{access_token: string|null, expires_at: int|null}
     */
    protected array $tokenCache = [
        'access_token' => null,
        'expires_at' => null,
    ];

    /**
     * Create a new DiDiT client instance
     *
     * @param  array  $config  Client configuration
     */
    public function __construct(array $config = [])
    {
        // Required configuration
        $this->clientId = $config['client_id'] ?? env('DIDIT_CLIENT_ID');
        $this->clientSecret = $config['client_secret'] ?? env('DIDIT_CLIENT_SECRET');
        $this->baseUrl = $config['base_url'] ?? env('DIDIT_BASE_URL', 'https://verification.didit.me');
        $this->authUrl = $config['auth_url'] ?? env('DIDIT_AUTH_URL', 'https://apx.didit.me');
        $this->webhookSecret = $config['webhook_secret'] ?? env('DIDIT_WEBHOOK_SECRET');

        // Optional configuration
        $this->tokenExpiryBuffer = $config['token_expiry_buffer'] ?? 300; // 5 minutes buffer
        $this->timeout = $config['timeout'] ?? 10; // 10 seconds
        $this->debug = $config['debug'] ?? false;

        // Validate required configuration
        $this->validateConfig();
    }

    /**
     * Validate required configuration
     *
     * @throws Exception
     */
    protected function validateConfig(): void
    {
        if (empty($this->clientId)) {
            throw new Exception('DIDIT_CLIENT_ID is required');
        }

        if (empty($this->clientSecret)) {
            throw new Exception('DIDIT_CLIENT_SECRET is required');
        }

        if (empty($this->baseUrl)) {
            throw new Exception('DIDIT_BASE_URL is required');
        }

        if (empty($this->authUrl)) {
            throw new Exception('DIDIT_AUTH_URL is required');
        }
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
     * Handle error reporting and formatting
     *
     * @param  Exception  $exception  Error object
     * @param  string  $context  Context where the error occurred
     * @return Exception Formatted error
     */
    protected function handleError(Exception $exception, string $context): Exception
    {
        $errorMessage = "DiDiT {$context} error: {$exception->getMessage()}";

        if ($exception instanceof RequestException) {
            $this->log('API Error Details:', $exception->response?->json() ?? []);
        }

        return new Exception($errorMessage, $exception->getCode(), $exception);
    }

    /**
     * Get an access token, either from cache or by requesting a new one
     *
     * @return string The access token
     *
     * @throws Exception If authentication fails
     */
    public function getAccessToken(): string
    {
        $now = Carbon::now()->timestamp;

        // Return cached token if still valid
        if (
            $this->tokenCache['access_token'] &&
            $this->tokenCache['expires_at'] > ($now + $this->tokenExpiryBuffer)
        ) {
            $this->log('Using cached access token');

            return $this->tokenCache['access_token'];
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
                throw new Exception('Invalid response from auth server');
            }

            // Cache the token with expiry
            $this->tokenCache = [
                'access_token' => $data['access_token'],
                'expires_at' => $now + ($data['expires_in'] ?? 3600), // Default 1 hour if not specified
            ];

            return $this->tokenCache['access_token'];
        } catch (Exception $exception) {
            throw $this->handleError($exception, 'authentication');
        }
    }

    /**
     * Make an authenticated API request to DiDiT
     *
     * @param  string  $method  HTTP method
     * @param  string  $url  Request URL
     * @param  array  $options  Request options
     * @param  string  $context  Context for error handling
     * @return mixed API response
     *
     * @throws Exception If the request fails
     */
    protected function makeAuthenticatedRequest(
        string $method,
        string $url,
        array $options = [],
        string $context = 'API request'
    ) {
        try {
            $accessToken = $this->getAccessToken();

            $request = $this->createHttpClient()
                ->withToken($accessToken)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]);

            // Add any additional headers
            if (isset($options['headers'])) {
                $request->withHeaders($options['headers']);
                unset($options['headers']);
            }

            // Handle response type
            if (isset($options['responseType']) && $options['responseType'] === 'arraybuffer') {
                $request->withOptions(['sink' => $options['sink'] ?? null]);
                unset($options['responseType']);
                unset($options['sink']);
            }

            // Make the request
            $response = $request->$method($url, $options);

            if ($response->failed()) {
                $response->throw();
            }

            // Handle binary response if needed
            if (isset($options['responseType']) && $options['responseType'] === 'arraybuffer') {
                return $response->body();
            }

            return $response->json();
        } catch (Exception $exception) {
            throw $this->handleError($exception, $context);
        }
    }

    /**
     * Create a new verification session
     *
     * @param  string  $callbackUrl  The URL to redirect to after verification
     * @param  array|null  $vendorData  Optional custom data
     * @param  array  $options  Additional session options
     * @return array Session data
     *
     * @throws Exception If session creation fails
     */
    public function createSession(
        string $callbackUrl,
        ?array $vendorData = null,
        array $options = []
    ): array {
        if (empty($callbackUrl)) {
            throw new Exception('callback_url is required');
        }

        $data = [
            'callback' => $callbackUrl,
            ...$options,
        ];

        if ($vendorData) {
            $data['vendor_data'] = $vendorData;
        }

        return $this->makeAuthenticatedRequest(
            'post',
            "{$this->baseUrl}/v1/session/",
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
     * @throws Exception If PDF generation fails
     */
    public function generateSessionPDF(string $sessionId): string
    {
        if (empty($sessionId)) {
            throw new Exception('sessionId is required');
        }

        $url = "{$this->baseUrl}/v1/session/{$sessionId}/generate-pdf/";
        $accessToken = $this->getAccessToken();

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Accept' => 'application/pdf',
                ])
                ->timeout($this->timeout)
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
     * @param  string  $newStatus  The new status ('Approved' or 'Declined')
     * @param  string|null  $comment  Optional comment
     * @return array Updated session data
     *
     * @throws Exception If status update fails
     */
    public function updateSessionStatus(
        string $sessionId,
        string $newStatus,
        ?string $comment = null
    ): array {
        if (empty($sessionId)) {
            throw new Exception('sessionId is required');
        }

        if (! in_array($newStatus, ['Approved', 'Declined'])) {
            throw new Exception('newStatus must be either "Approved" or "Declined"');
        }

        $data = [
            'new_status' => $newStatus,
        ];

        if ($comment) {
            $data['comment'] = $comment;
        }

        return $this->makeAuthenticatedRequest(
            'patch',
            "{$this->baseUrl}/v1/session/{$sessionId}/update-status/",
            $data,
            'status update'
        );
    }

    /**
     * Retrieve details of an existing session
     *
     * @param  string  $sessionId  The ID of the session
     * @return array Session data
     *
     * @throws Exception If session retrieval fails
     */
    public function getSession(string $sessionId): array
    {
        if (empty($sessionId)) {
            throw new Exception('sessionId is required');
        }

        return $this->makeAuthenticatedRequest(
            'get',
            "{$this->baseUrl}/v1/session/{$sessionId}/decision/",
            [],
            'session retrieval'
        );
    }

    /**
     * Verify the signature of a webhook payload
     *
     * @param  array  $headers  The headers from the webhook request
     * @param  string  $rawBody  The raw body of the webhook request
     * @return array The parsed webhook event data
     *
     * @throws Exception If signature verification fails
     */
    public function verifyWebhookSignature(array $headers, string $rawBody): array
    {
        if (empty($this->webhookSecret)) {
            throw new Exception('DIDIT_WEBHOOK_SECRET is required for webhook verification');
        }

        $signature = $headers['x-signature'] ?? null;
        $timestamp = $headers['x-timestamp'] ?? null;

        // Ensure all required data is present
        if (! $signature || ! $timestamp || empty($rawBody)) {
            throw new Exception('Missing required webhook verification data');
        }

        // Validate the timestamp to ensure the request is fresh (within 5 minutes)
        $currentTime = Carbon::now()->timestamp;
        $incomingTime = (int) $timestamp;

        if (abs($currentTime - $incomingTime) > 300) {
            throw new Exception('Request timestamp is stale');
        }

        // Generate an HMAC from the raw body using the shared secret
        $expectedSignature = hash_hmac('sha256', $rawBody, $this->webhookSecret);

        // Compare using hash_equals for timing attack protection
        if (! hash_equals($expectedSignature, $signature)) {
            throw new Exception('Invalid webhook signature');
        }

        // Signature is valid, parse and return the payload
        return json_decode($rawBody, true);
    }

    /**
     * Process a webhook request by verifying its signature and parsing the payload
     *
     * @param  Request  $request  The Laravel request object
     * @return array The parsed and verified webhook event data
     *
     * @throws Exception If webhook processing fails
     */
    public function processWebhook(Request $request): array
    {
        $rawBody = $request->getContent();

        if (empty($rawBody)) {
            throw new Exception('Request content is empty');
        }

        $headers = $request->header();
        // Laravel headers are arrays, so we need to get the first value
        $webhookHeaders = [
            'x-signature' => $request->header('x-signature'),
            'x-timestamp' => $request->header('x-timestamp'),
        ];

        $payload = $this->verifyWebhookSignature($webhookHeaders, $rawBody);
        $this->log('Webhook verified and processed', $payload);

        return $payload;
    }
}
