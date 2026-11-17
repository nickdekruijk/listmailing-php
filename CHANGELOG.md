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
- `SubscribeOutcome::awaitsConfirmation()` and `SubscribeOutcome::isOnList()`.
- `ConnectionFailed` leaves the email address of a status lookup out of its message, and does not keep the Guzzle exception, whose message holds the full URL.
- Laravel layer, auto-discovered: config from `.env`, the `ListMailing` facade with `ListMailing::configured()`, and `ListMailing::fake()` with asserts for the tests of a site.
- The queued `SubscribeToList` job. It retries timeouts, 5xx and 429 after `Retry-After` with an Idempotency-Key fixed at dispatch, for 48 hours counted from the moment it becomes available, so after a delay. Any other error, a missing token or list id included, is reported and fails the job; it never throws, also on the sync queue.
- The `SignUpProcessed` event after every successful answer, and `SignUpFailed` once when the job fails for good, fired from the job's `failed()` so a timeout or an expired retry period on the worker counts too.
