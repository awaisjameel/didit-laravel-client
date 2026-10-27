# Contributing

Install dependencies with `composer install`. Use PHP 8.2–8.5 with Laravel 12,
or PHP 8.3–8.5 with Laravel 13. Run `composer test`, `composer analyse`, and
`vendor/bin/pint --test` before submitting a change.

Follow the current [Didit API documentation](https://docs.didit.me/llms.txt).
Include regression tests for request payloads, error handling and webhook changes.
Fake HTTP requests and use synthetic identities and signing secrets in tests.
Tests must not call real verification services or use production credentials.

Keep signature fixtures independent of the implementation being tested. Preserve
JSON object/list distinctions, Unicode, complete payload authentication and signed
timestamp freshness. Document public API changes in the README and changelog.
