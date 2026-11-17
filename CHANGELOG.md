# Changelog

All notable changes to `nickdekruijk/listmailing-php` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-11-17

### Added

- `Client` for the ListMailing sign-up API v1 on Guzzle: `subscribe()` and `status()`, with a default list, an Idempotency-Key per sign-up and https only.
- `Subscription` with the consent fields (`source`, `reference`, `consentedAt`, `consentText`) and `Confirmation` (`Email`, `Link`, `None`).
- Typed answers: `SubscribeResult` with the `SubscribeOutcome` enum, `StatusResult` with the `MembershipStatus` enum.
- An exception per error code, all under `ListMailingException`. `RateLimited`, `DailyLimitReached`, `ServerError` and `ConnectionFailed` implement `Retryable` and carry `retryAfter`; `ValidationFailed` carries the errors per field.
- Laravel layer, auto-discovered: config from `.env`, the `ListMailing` facade, the queued `SubscribeToList` job that retries timeouts, 5xx and 429 after `Retry-After` with a fixed Idempotency-Key, the `SignUpProcessed` event, and `ListMailing::fake()` with asserts for the tests of a site.
