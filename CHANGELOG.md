# Changelog

All notable changes to `nickdekruijk/listmailing-php` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.2] - 2026-11-17

### Changed

- Guzzle 8 is supported next to Guzzle 7 (`guzzlehttp/guzzle: ^7.8|^8.0`). A project on Guzzle 8, such as one on Laravel 13 that already moved to it, could not install 0.1.1. The client works the same on both: every request option it sends has the type Guzzle 8 validates, and it catches `GuzzleException`, the base of every transfer failure in both versions.

## [0.1.1] - 2026-11-17

### Fixed

- **A site in another language no longer loses sign-ups.** ListMailing only knows `nl` and `en` and answers any other `locale` with a 422, which fails the job for good. `Subscription` now turns `nl_NL`, `en-GB`, `EN` and the like into `nl` or `en`, and leaves any other language out, so ListMailing uses the language of the page. `locale: app()->getLocale()` is safe on a site in German.
- **A timeout of 0 is refused.** Guzzle reads 0 as no timeout at all, so `LISTMAILING_TIMEOUT=0` meant waiting forever. `Client` now throws an `InvalidArgumentException` on 0 or less; in the job that fails the job like a missing token. The Laravel config does not trip over it: an empty `LISTMAILING_TIMEOUT`, as copied from `.env.example`, 0 or a value that is not a number falls back to 10 seconds.

### Added

- `Client::withTimeout($seconds)` returns a copy with a shorter timeout, for a call a visitor waits on, such as asking for a confirmation link while a booking is saved. `ClientFake` has it too and returns itself, so `ListMailing::withTimeout(3)->subscribe(...)` works in tests. It is not part of `ClientInterface`, so an implementation of its own does not break.
- A fallback language: the `defaultLocale` argument of `Client`, or `LISTMAILING_LOCALE` in Laravel, is sent when a sign-up has no language ListMailing knows. A German visitor of a site with an English list then gets the English confirmation mail instead of the language of the page.
- `Subscription::normalizeLocale()`, the rule that turns `nl_NL` into `nl` and `de` into null.
- `Client::DEFAULT_TIMEOUT`.
- `SubscribeToList::idempotencyKey()` takes an optional list id. Without one it uses `listmailing.list`, as the job does, and gives the key the job would send. Passing `config('listmailing.list')` when it was null gave a `TypeError`.

### Changed

- The README example for a blocked address catches what the listener throws and gives its call a key of its own. The listener runs inside the job, so an exception failed a job whose sign-up had gone through.
- The README example with the link in the booking mail uses a timeout of 3 seconds, and also catches the `InvalidArgumentException` of a missing token or list, so the booking goes on.

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
