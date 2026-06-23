# Changelog

All notable changes to `didit-laravel-client` will be documented in this file.

## 2.0.0 - 2026-06-24

Major release. The client is upgraded to Didit's **v3 API** and the package
internals were hardened (typed exceptions, strict types, cache-backed tokens,
an event for webhooks, and skeleton cleanup). **This is a breaking release** —
see "Upgrading from 1.x" in the README/release notes.

### Changed (breaking)

-   **Authentication:** now uses an `x-api-key` header (`DIDIT_API_KEY`). The legacy OAuth2 `client_credentials` flow is still supported as an automatic fallback when no API key is configured.
-   **API version:** all session endpoints moved from `/v1/` to `/v3/` (configurable via `DIDIT_API_VERSION`).
-   **`createSession()`:** now requires a `workflow_id` (passed in `$options` or via `DIDIT_WORKFLOW_ID`) instead of a free-form `features` string. `callbackUrl` is now optional. The response returns `url` (previously `verification_url`).
-   **`updateSessionStatus()`:** accepts `Resubmitted` in addition to `Approved`/`Declined`, plus an `$options` array for extra fields (`send_email`, `email_address`, …).
-   **Minimum PHP bumped to `^8.1`** to match the typed properties used and the supported Laravel versions (10/11/12/13).
-   **`declare(strict_types=1)` enabled across the package.** Numeric/boolean config values are now cast explicitly so env-provided strings stay valid under strict typing.

### Added

-   **Typed exception hierarchy:** `DiditException` (base) with `DiditConfigurationException`, `DiditAuthenticationException`, `DiditRequestException` (exposes `getResponse()`/`status()`), and `WebhookVerificationException`. Invalid arguments now throw `\InvalidArgumentException`. All extend `\Exception`, so existing `catch (\Exception)` code keeps working.
-   **`DiditWebhookReceived` event,** dispatched by `processWebhook()` after a webhook is verified, so you can handle webhooks in a listener instead of inline.
-   **Webhook verification now supports all three Didit signature schemes,** tried in order: `x-signature-v2` (recommended), `x-signature-simple`, then legacy `x-signature`.
-   **The client is bound in the container as a singleton** by the service provider; resolve the shared instance with `app(DiditLaravelClient::class)`.
-   New config keys: `api_key`, `workflow_id`, `api_version`.
-   Tightened PHPDoc array shapes (`array<string, mixed>`) on the public API.

### Changed

-   Legacy OAuth2 access tokens are now cached through Laravel's cache store (shared across requests/workers) instead of only in-memory for the current request.
-   `endpoint()` trims a trailing slash from the base URL to avoid double slashes.
-   Webhook timestamp freshness check now parses ISO-8601 timestamps (legacy `created_at`) instead of truncating them via an `(int)` cast.

### Removed

-   Redundant `env()` calls inside the client (values now resolve through the package config, which is config-cache safe).
-   Unused boilerplate console command (`DiditLaravelClientCommand`).
-   Hardcoded `version` field from `composer.json` (versions are derived from Git tags).
-   Unused package skeleton: the commented-out model factory, the stub migration (`create_didit_laravel_client_table`), the placeholder `ExampleTest`, and the now-empty `database/` autoload mapping and PHPStan path. This package is a stateless API client with no database layer.
