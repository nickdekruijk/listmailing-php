# ListMailing PHP

A client for the sign-up API of [ListMailing](https://listmailing.nl): sign people up for a newsletter list from your own server, for example with a checkbox on a booking or quote form. ListMailing takes care of the confirmation, the unsubscribe link and the rest.

The core is plain PHP on Guzzle. A Laravel layer in the same package adds config, a facade, a queued job that retries safely and a fake for your tests.

This package is 0.x while the API can still change. It covers signing up and looking up a status, nothing else. The API itself is described in the ListMailing manual, under Sign-up API.

## Installation

```
composer require nickdekruijk/listmailing-php
```

PHP 8.3 or newer. The Laravel layer works with Laravel 12 and 13 and is auto-discovered.

Create a token in ListMailing under **API**, choose the lists it may sign people up for, and copy the token, the base URL and the list id. Keep the token on your server only: never put it in JavaScript or HTML a visitor can see.

## Without Laravel

```php
use NickDeKruijk\ListMailing\Client;
use NickDeKruijk\ListMailing\Subscription;

$listMailing = new Client(
    token: getenv('LISTMAILING_TOKEN'),
    defaultList: 'your-list-id',
);

$result = $listMailing->subscribe(
    new Subscription(
        email: 'anna@example.com',
        firstName: 'Anna',
        locale: 'en',
        source: 'booking-form',
        reference: 'B-2026-0123',
        consentedAt: new DateTimeImmutable,
        consentText: 'Yes, send me the newsletter',
    ),
    idempotencyKey: 'B-2026-0123',
);

$result->outcome;         // SubscribeOutcome::Pending
$result->created;         // true when the address is new to this list (HTTP 201)
$result->subscriberId;    // null until the address is confirmed
$result->confirmationUrl; // only with Confirmation::Link
```

The constructor also takes `baseUrl` (default `https://listmailing.nl/api/v1`, only https is accepted), `timeout` in seconds (default 10) and `http`, your own Guzzle client. Pass a list id as the second argument of `subscribe()` and `status()` to use another list than the default.

### Outcomes

`$result->outcome` is a `SubscribeOutcome`:

| Case | Meaning |
|---|---|
| `Pending` | A confirmation was asked for. |
| `AlreadySubscribed` | Already on the list. Nothing was sent. |
| `ResubscribePending` | Unsubscribed before and signing up again. A confirmation was asked for. |
| `Subscribed` | On the list straight away, only with `Confirmation::None`. |
| `Suppressed` | The address is blocked after a spam complaint or a hard bounce. Nothing was sent. See below. |

`$result->outcome->awaitsConfirmation()` is true for `Pending` and `ResubscribePending`, `$result->outcome->isOnList()` for `Subscribed` and `AlreadySubscribed`.

### Confirmation

The `confirmation` field of a `Subscription` takes a `Confirmation`:

- `Confirmation::Email`, the default: ListMailing sends a confirmation mail from your sender.
- `Confirmation::Link`: nothing is sent, you get `confirmationUrl` back to put as a button in a mail you send anyway, such as the booking confirmation. One mail instead of two, still a real double opt-in. The link is valid for 30 days.
- `Confirmation::None`: on the list straight away. Only after ListMailing has enabled single opt-in for your account; until then you get `SingleOptInNotEnabled`.

A suppressed address gets nothing with `Email` or `None`. Only a confirmation link the customer clicks themselves lifts the block, so use `Link` and put the button in a mail of your own.

### Looking up a status

```php
$status = $listMailing->status('anna@example.com');

$status->status;       // MembershipStatus::Subscribed, Pending, Unsubscribed, Suppressed or NotSubscribed
$status->subscriberId;
```

### Idempotency-Key

Send an Idempotency-Key with every sign-up, such as your own reference. The same key within 24 hours returns the same answer and does nothing again, so a retry after a timeout never mails twice. ListMailing keeps keys per token, not per list: use a key of its own per list and address. Only successful answers are kept, so after an error the same key can simply be tried again.

### Errors

Every error is a `NickDeKruijk\ListMailing\Exceptions\ListMailingException` with `errorCode` and `httpStatus`:

| Exception | When |
|---|---|
| `Unauthenticated` | 401: no token, an unknown or a revoked token. |
| `PlanRequired` | 403: the account has no plan with the API. |
| `ListNotAllowed` | 403: the token may not use this list. |
| `SingleOptInNotEnabled` | 403: `Confirmation::None` without approval. |
| `HttpsRequired` | 403: the request did not come in over https. |
| `NotFound` | 404: the list does not exist or is not yours. |
| `ValidationFailed` | 422: `errors()` names what is wrong per field. |
| `RateLimited` | 429: too many requests per minute. `retryAfter` in seconds. |
| `DailyLimitReached` | 429: the sign-ups for today are used up. `retryAfter` runs until midnight UTC. |
| `ServerError` | 5xx: a failure on ListMailing's side. `retryAfter` when the answer gave one. |
| `ConnectionFailed` | No answer: a timeout or a network error. The message leaves the email address out. |
| `UnexpectedResponse` | An answer the client does not understand. |

`RateLimited`, `DailyLimitReached`, `ServerError` and `ConnectionFailed` implement `Retryable`: try the same request again later, with the same Idempotency-Key. The others will fail again until something changes.

Without a list id and without a default list, and on an empty token or a base URL that is not https, the client throws an `InvalidArgumentException`.

## With Laravel

Add the token and the list id to `.env`:

```
LISTMAILING_TOKEN=lm_...
LISTMAILING_LIST=your-list-id
```

That is all. To change more, publish the config:

```
php artisan vendor:publish --tag=listmailing-config
```

| Key | Env | Default |
|---|---|---|
| `token` | `LISTMAILING_TOKEN` | |
| `base_url` | `LISTMAILING_BASE_URL` | `https://listmailing.nl/api/v1` |
| `list` | `LISTMAILING_LIST` | the default list |
| `timeout` | `LISTMAILING_TIMEOUT` | `10` |
| `queue` | `LISTMAILING_QUEUE` | the default queue |

A missing token does not break your site: the client is built on first use, and only that call fails. `ListMailing::configured()` tells whether a token and a default list are set, so you can leave the newsletter box out where they are not, for example on a local copy:

```blade
@if (\NickDeKruijk\ListMailing\Laravel\ListMailing::configured())
    {{-- the newsletter box --}}
@endif
```

### Facade

```php
use NickDeKruijk\ListMailing\Laravel\ListMailing;

$result = ListMailing::subscribe($subscription);
$status = ListMailing::status('anna@example.com');
```

To inject the client, type-hint `NickDeKruijk\ListMailing\ClientInterface`, not `Client`, so the fake can take its place in tests.

### The job

Sign people up from a queued job, so an error from the API never stops a booking:

```php
use NickDeKruijk\ListMailing\Laravel\SubscribeToList;

SubscribeToList::dispatch($subscription);
SubscribeToList::dispatch($subscription, 'another-list-id');
```

- A timeout, a 5xx, `RateLimited` or `DailyLimitReached` is tried again later. The job waits the seconds of `Retry-After`, or 1, 5, 15, 30 and then 60 minutes when there is none, and keeps trying for 48 hours, counted from the moment the job becomes available, so after any `delay()`. A full day thus goes through after midnight UTC.
- Every try sends the same Idempotency-Key, fixed when the job is dispatched, so a retry never mails twice. With a `reference` the key is derived from the list, the address, the confirmation and the reference: dispatching the same booking twice signs up once.
- Any other error is reported to your exception handler and fails the job, without a retry. So is a missing token or list id, and a retryable error once the 48 hours are over.
- The job never throws. On the `sync` queue a booking still goes through, but nothing is tried again; use a real queue in production.
- The job is dispatched after the database transaction commits, so a booking that is rolled back signs no one up.

The job fires two events, so your site can keep track:

- `NickDeKruijk\ListMailing\Laravel\SignUpProcessed` after every successful answer, with `subscription`, `listId` and `result`. Also for `Suppressed`, see [A blocked address](#a-blocked-address).
- `NickDeKruijk\ListMailing\Laravel\SignUpFailed` once when the job fails for good, with `subscription`, `listId` (null when none was set) and `exception`. Also when the worker gives up on it, after a timeout or once the retry period passed in the queue. Not when it only waits to try again.

```php
use Illuminate\Support\Facades\Event;
use NickDeKruijk\ListMailing\Laravel\SignUpFailed;
use NickDeKruijk\ListMailing\Laravel\SignUpProcessed;

Event::listen(function (SignUpProcessed $event) {
    Booking::where('reference', $event->subscription->reference)
        ->update(['newsletter' => $event->result->outcome->value]);
});

Event::listen(function (SignUpFailed $event) {
    Booking::where('reference', $event->subscription->reference)
        ->update(['newsletter' => 'failed']);
});
```

## Example: a booking form with consent

Show the checkbox with a fixed sentence, and send that same sentence as the consent text. Keep it in a translation, so the form and the sign-up never drift apart:

```php
// lang/en/newsletter.php
return [
    'consent' => 'Yes, keep me posted about new boats and offers',
];
```

```blade
@if (\NickDeKruijk\ListMailing\Laravel\ListMailing::configured())
    <label>
        <input type="checkbox" name="newsletter" value="1" @checked(old('newsletter'))>
        {{ __('newsletter.consent') }}
    </label>
@endif
```

Sign up after the booking is saved:

```php
use NickDeKruijk\ListMailing\Laravel\SubscribeToList;
use NickDeKruijk\ListMailing\Subscription;

public function store(BookingRequest $request)
{
    $booking = Booking::create($request->validated());

    if ($request->boolean('newsletter')) {
        SubscribeToList::dispatch($this->subscription($booking));
    }

    return redirect()->route('booking.thanks', $booking);
}

private function subscription(Booking $booking): Subscription
{
    return new Subscription(
        email: $booking->email,
        firstName: $booking->first_name,
        lastName: $booking->last_name,
        locale: app()->getLocale(),
        source: 'booking-form',
        reference: $booking->reference,
        consentedAt: now(),
        consentText: __('newsletter.consent'),
    );
}
```

The customer gets a confirmation mail from ListMailing. To put the confirmation in your own booking mail instead, ask for a link. That needs the answer right away, so it is a direct call and not the job. Fall back to the job when it fails, so the booking mail still goes out:

```php
use NickDeKruijk\ListMailing\Confirmation;
use NickDeKruijk\ListMailing\Exceptions\ListMailingException;
use NickDeKruijk\ListMailing\Laravel\ListMailing;
use NickDeKruijk\ListMailing\Laravel\SubscribeToList;

$confirmationUrl = null;

if ($request->boolean('newsletter')) {
    $subscription = $this->subscription($booking)->withConfirmation(Confirmation::Link);

    try {
        $confirmationUrl = ListMailing::subscribe(
            $subscription,
            idempotencyKey: SubscribeToList::idempotencyKey($subscription, config('listmailing.list')),
        )->confirmationUrl;
    } catch (ListMailingException) {
        // No link for the booking mail: ListMailing mails the confirmation itself.
        SubscribeToList::dispatch($subscription->withConfirmation(Confirmation::Email));
    }
}

Mail::to($booking->email)->send(new BookingConfirmation($booking, $confirmationUrl));
```

`$confirmationUrl` stays null when the address is already on the list; then leave the button out.

### A blocked address

An address that made a spam complaint or bounced hard gets `Suppressed` with `Email` and with `None`: ListMailing sends it nothing, not even a confirmation. Only a confirmation link the customer clicks themselves lifts the block. With the job, handle it in a listener: ask for a link and send it in a mail of your own.

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use NickDeKruijk\ListMailing\Confirmation;
use NickDeKruijk\ListMailing\Laravel\ListMailing;
use NickDeKruijk\ListMailing\Laravel\SignUpProcessed;
use NickDeKruijk\ListMailing\SubscribeOutcome;

Event::listen(function (SignUpProcessed $event) {
    if ($event->result->outcome !== SubscribeOutcome::Suppressed) {
        return;
    }

    $link = ListMailing::subscribe($event->subscription->withConfirmation(Confirmation::Link), $event->listId)->confirmationUrl;

    if ($link !== null) {
        Mail::to($event->subscription->email)->send(new ConfirmNewsletter($link));
    }
});
```

With the link in your booking mail, as above, you never get `Suppressed`: the link already is the way back.

## Example: your own confirmation, then single opt-in

You can also confirm the sign-up yourself: send your own confirmation mail with a link to a page on your site, and only sign up once the customer has confirmed there. ListMailing then needs no confirmation of its own, so the sign-up goes with `Confirmation::None`. That needs single opt-in, which ListMailing enables on request; until then the job fails with `SingleOptInNotEnabled` and fires `SignUpFailed`.

Let the link open a page with a button that posts, and sign up on the POST only. Many business mail servers open every link in a mail to scan it, so a link that signs up by itself would sign up people who never clicked.

```php
// routes/web.php
Route::get('newsletter/confirm/{booking}', [NewsletterController::class, 'show'])
    ->name('newsletter.confirm')->middleware('signed');
Route::post('newsletter/confirm/{booking}', [NewsletterController::class, 'store'])
    ->name('newsletter.confirm.store')->middleware('signed');
```

```php
use NickDeKruijk\ListMailing\Confirmation;
use NickDeKruijk\ListMailing\Laravel\SubscribeToList;
use NickDeKruijk\ListMailing\Subscription;

class NewsletterController
{
    public function show(Booking $booking)
    {
        return view('newsletter.confirm', ['booking' => $booking]);
    }

    public function store(Booking $booking)
    {
        SubscribeToList::dispatch(new Subscription(
            email: $booking->email,
            firstName: $booking->first_name,
            locale: app()->getLocale(),
            source: 'booking-confirm',
            reference: $booking->reference,
            consentedAt: now(),
            consentText: __('newsletter.consent'),
            confirmation: Confirmation::None,
        ));

        return view('newsletter.confirmed');
    }
}
```

The form on the confirm page posts to the same signed URL, for example `action="{{ URL::signedRoute('newsletter.confirm.store', $booking) }}"`. Send `consentedAt` as the moment of that click, and `consentText` as the sentence the customer agreed to. A blocked address still answers `Suppressed`: handle it as in [A blocked address](#a-blocked-address), with a link.

## Testing your site

`ListMailing::fake()` puts a fake in place of the client. The job uses it too. Nothing is sent and every call is recorded:

```php
use NickDeKruijk\ListMailing\Laravel\ListMailing;
use NickDeKruijk\ListMailing\Subscription;

it('signs up for the newsletter when the box is ticked', function () {
    $listMailing = ListMailing::fake();

    $this->post('/booking', [...$booking, 'newsletter' => '1']);

    $listMailing->assertSubscribed('anna@example.com');
    $listMailing->assertSubscribed(fn (Subscription $subscription) => $subscription->consentText !== null);
});

it('does not sign up without the box', function () {
    $listMailing = ListMailing::fake();

    $this->post('/booking', $booking);

    $listMailing->assertNothingSubscribed();
});
```

The fake answers `Pending`, with a fake `confirmationUrl` when the sign-up asked for a link. Tell it otherwise with `respondWith()`, which takes outcomes, results or exceptions, used in order:

```php
use NickDeKruijk\ListMailing\Exceptions\DailyLimitReached;
use NickDeKruijk\ListMailing\SubscribeOutcome;

ListMailing::fake()->respondWith(
    SubscribeOutcome::AlreadySubscribed,
    new DailyLimitReached('Full.', 'daily_limit_reached', 429, 3600),
);
```

Status lookups answer `NotSubscribed`, or what you pass to `statusWith()`. The asserts: `assertSubscribed($emailOrClosure, $listId = null)`, `assertNotSubscribed(...)`, `assertNothingSubscribed()`, `assertSubscribedTimes($times)` and `assertStatusChecked($email, $listId = null)`. `subscriptions()` returns every recorded sign-up. `ListMailing::configured()` only reads the config, also with the fake: set `listmailing.token` and `listmailing.list` in your tests when a view depends on it. With the queue faked, use `Queue::assertPushed(SubscribeToList::class)` instead.

## Tests

```
composer install
composer test
composer types:check
```

## License

MIT, see [LICENSE.md](LICENSE.md).
