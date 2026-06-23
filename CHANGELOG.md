# Changelog

All notable changes to `didit-laravel-client` will be documented in this file.

## v2.0.0 — Didit v3 API + hardened internals - 2026-06-23

v2.0.0 — Didit v3 API + hardened internals

This is a major, breaking release. The client is upgraded to Didit's v3 API and
the package internals were reworked for type safety and reliability. Existing
`catch (\Exception)` code keeps working, but the request/response shapes and the
authentication model have changed — read "Upgrading from 1.x" below.

────────────────────────────────────────────────────────────────────────
Breaking changes
────────────────────────────────────────────────────────────────────────
• Authentication — API key first. Requests now send an `x-api-key` header
(set `DIDIT_API_KEY`). The legacy OAuth2 `client_credentials` flow still
works as an automatic fallback when no API key is configured.
• API version — endpoints moved from `/v1/` to `/v3/` (override with
`DIDIT_API_VERSION`).
• createSession() — now workflow-based. A `workflow_id` is required (pass it in
`$options` or set `DIDIT_WORKFLOW_ID`) instead of the old free-form `features`
string. `callbackUrl` is now optional. The response field `verification_url`
is now `url`.
• updateSessionStatus() — accepts `Resubmitted` in addition to
`Approved` / `Declined`, and takes an `$options` array for extra fields
(e.g. `send_email`, `email_address`).
• Minimum PHP is now ^8.1 (matches the typed properties and Laravel 10–13).
• `declare(strict_types=1)` is enabled package-wide; numeric/boolean config
values are cast explicitly so env strings stay valid.

────────────────────────────────────────────────────────────────────────
Added
────────────────────────────────────────────────────────────────────────
• Typed exception hierarchy — `DiditException` (base) with
`DiditConfigurationException`, `DiditAuthenticationException`,
`DiditRequestException` (exposes `getResponse()` / `status()`) and
`WebhookVerificationException`. Invalid arguments throw
`\InvalidArgumentException`. All extend `\Exception`.
• `DiditWebhookReceived` event — dispatched by `processWebhook()` after a
webhook is verified, so webhooks can be handled in a listener.
• Multi-scheme webhook verification — tries `x-signature-v2` (recommended),
then `x-signature-simple`, then legacy `x-signature`. Comparisons are
timing-attack safe; stale requests (>5 min via `x-timestamp`) are rejected.
• Container singleton — the service provider binds the client as a singleton;
resolve the shared instance with `app(DiditLaravelClient::class)`.
• New config keys: `api_key`, `workflow_id`, `api_version`.

────────────────────────────────────────────────────────────────────────
Changed / Removed
────────────────────────────────────────────────────────────────────────
• OAuth2 access tokens are cached through Laravel's cache store (shared across
requests/workers) and refreshed before expiry, instead of per-request memory.
• `endpoint()` trims a trailing slash from the base URL to avoid double slashes.
• Webhook freshness check parses ISO-8601 timestamps instead of `(int)` casting.
• Removed: redundant in-client `env()` calls (config-cache safe now), the unused
console command, the hardcoded `version` in composer.json (now derived from
Git tags), and the unused package skeleton (model factory, stub migration,
ExampleTest, empty database/ autoload + PHPStan path). This is a stateless
API client with no database layer.

────────────────────────────────────────────────────────────────────────
Upgrading from 1.x
────────────────────────────────────────────────────────────────────────

1. Set `DIDIT_API_KEY` (and `DIDIT_WORKFLOW_ID`). You can drop the OAuth2
   client id/secret unless you still rely on the legacy fallback.
2. Replace any `features` argument to `createSession()` with a `workflow_id`.
3. Read the session link from the `url` field (was `verification_url`).
4. Ensure your runtime is PHP 8.1+.
5. Optionally, catch the new typed exceptions for finer-grained handling;
   existing `catch (\Exception)` blocks continue to work.

Full configuration and usage examples are in the README and CHANGELOG.
