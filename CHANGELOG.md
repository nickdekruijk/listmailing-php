# Changelog

All notable changes to `nickdekruijk/listmailing-php` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.3] - 2026-11-17

Found in an audit of the package against the API it talks to. Nothing changes for code that works today, except where it says so.

### Fixed

- **A listener that throws no longer repeats the job for two days.** `SubscribeToList` fired `SignUpProcessed` outside its error handling. An exception from a listener, the README's own `Booking::update()` when the database hiccups for instance, escaped the job. The worker then tried it again at once, for 48 hours: every try replayed the request, fired the event again and spent the rate limit of the token, and after ListMailing forgot the Idempotency-Key it became a new sign-up with a new mail. The exception is now reported to your exception handler and the job is done, because the sign-up went through. On the `sync` queue it no longer breaks the request either. The job also has a `backoff()` now, for a job the worker puts back itself.
- **The token no longer ends up in error messages.** A token read from a secret file that ends in a newline made the HTTP library refuse the header and quote it, token included, into logs, the error tracker and `failed_jobs`. The token is now trimmed, and one that holds a space, a line break or a control character is refused with a message that does not repeat it.
- **`dump()`, `dd()` and `var_export()` no longer show the token.** The docblock said so, but only `var_dump()` and `print_r()` left it out. The token is no longer a property of the client.
- **A status added to the API later no longer fails every sign-up.** An unknown `status` in a successful answer threw `UnexpectedResponse`, which failed the job for good although the address was signed up. It now gives `SubscribeOutcome::Unknown`, with the word ListMailing sent in `$result->status`, and `SignUpProcessed` fires as usual. A successful answer without a status still throws.
- **Text that is not valid UTF-8 is refused straight away.** A name from an old latin1 table was reported as "could not be reached" and tried again for two days. It now throws an `InvalidArgumentException` before anything is sent.
- **Any 429 is tried again.** A 429 from a proxy or firewall in front of ListMailing, without ListMailing's error code or without JSON, was an `UnexpectedResponse` and failed the job. It is now a `RateLimited`, with its `Retry-After`.
- **A job waits at most twelve hours at a time** (`SubscribeToList::MAX_RELEASE`). After a full day the wait can be up to 24 hours, and a queue such as SQS refuses a delay over 12. The job then asks again and waits once more. Whether there is time left to wait at all is still judged on the whole wait.
- **A sign-up queued by 0.1.0 with a language ListMailing does not know** was still sent with it after an upgrade and answered with a 422. `Subscription::toArray()` drops it now too.
- The job reads Laravel's clock instead of `time()`, so a test of your site that freezes time sees one moment.
- The "blocked address" example in the README had `catch (Throwable $e)` without importing `Throwable`, which catches nothing in namespaced code.

### Changed

- **`ClientFake` answers `Subscribed` for a sign-up with `Confirmation::None`**, as ListMailing does, where it answered `Pending`. A test that relied on `Pending` there has to say so with `respondWith()`.
- The README and the docblocks follow two changes on ListMailing's side. A confirmation link handed back with `Confirmation::Link` lifts a block after a hard bounce, but no longer one after a spam complaint: that sign-up answers `Suppressed` without a link. And an Idempotency-Key now counts per token and per list.

### Added

- `SubscribeOutcome::Unknown` and `SubscribeResult::$status`, see above.
- `ClientFake::assertAllAnswersUsed()` fails when an answer given to `respondWith()` or `statusWith()` was never asked for, so a test cannot quietly assert less than it set up.
- A section in the README on the personal data a queued sign-up leaves in `jobs` and `failed_jobs`.
- CI runs with read-only permissions and checks `composer validate` and `composer audit`.

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
