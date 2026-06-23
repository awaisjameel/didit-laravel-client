# Changelog

All notable changes to `didit-laravel-client` will be documented in this file.

## Unreleased

### Added

-   Typed exception hierarchy: `DiditException` (base) with `DiditConfigurationException`, `DiditAuthenticationException`, `DiditRequestException` (exposes `getResponse()`/`status()`), and `WebhookVerificationException`. Invalid arguments now throw `\InvalidArgumentException`. All extend `\Exception`, so existing `catch (\Exception)` code keeps working.
-   `DiditWebhookReceived` event, dispatched by `processWebhook()` after a webhook is verified.

### Changed

-   Legacy OAuth2 access tokens are now cached through Laravel's cache store (shared across requests/workers) instead of only in-memory for the current request.
-   `endpoint()` trims a trailing slash from the base URL to avoid double slashes.
-   Webhook timestamp freshness check now parses ISO-8601 timestamps (legacy `created_at`) instead of truncating them via an `(int)` cast.
-   Bumped minimum PHP to `^8.1` to match the typed properties used and the supported Laravel versions (10/11/12).
-   Enabled `declare(strict_types=1)` across the package and tightened PHPDoc array shapes (`array<string, mixed>`) on the public API. Numeric/boolean config values are now cast explicitly so env-provided strings stay valid under strict typing.

### Removed

-   Unused boilerplate console command (`DiditLaravelClientCommand`).
-   Hardcoded `version` field from `composer.json` (versions are derived from Git tags).
-   Unused package skeleton: the commented-out model factory, the stub migration (`create_didit_laravel_client_table`), the placeholder `ExampleTest`, and the now-empty `database/` autoload mapping and PHPStan path. This package is a stateless API client with no database layer.

## 2.0.0 - 2026-06-23

Upgraded the client to Didit's **v3 API**. This is a breaking release.

### Changed (breaking)

-   **Authentication:** now uses an `x-api-key` header (`DIDIT_API_KEY`). The legacy OAuth2 `client_credentials` flow is still supported as an automatic fallback when no API key is configured.
-   **API version:** all session endpoints moved from `/v1/` to `/v3/` (configurable via `DIDIT_API_VERSION`).
-   **`createSession()`:** now requires a `workflow_id` (passed in `$options` or via `DIDIT_WORKFLOW_ID`) instead of a free-form `features` string. `callbackUrl` is now optional. The response returns `url` (previously `verification_url`).
-   **`updateSessionStatus()`:** accepts `Resubmitted` in addition to `Approved`/`Declined`, plus an `$options` array for extra fields (`send_email`, `email_address`, …).

### Added

-   Webhook verification now supports all three Didit signature schemes, tried in order: `x-signature-v2` (recommended), `x-signature-simple`, then legacy `x-signature`.
-   New config: `api_key`, `workflow_id`, `api_version`.

### Removed

-   Redundant `env()` calls inside the client (values now resolve through the package config, which is config-cache safe).
