<?php

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use NickDeKruijk\ListMailing\ClientInterface;
use NickDeKruijk\ListMailing\Confirmation;
use NickDeKruijk\ListMailing\Exceptions\ConnectionFailed;
use NickDeKruijk\ListMailing\Exceptions\DailyLimitReached;
use NickDeKruijk\ListMailing\Exceptions\HttpsRequired;
use NickDeKruijk\ListMailing\Exceptions\ListNotAllowed;
use NickDeKruijk\ListMailing\Exceptions\NotFound;
use NickDeKruijk\ListMailing\Exceptions\PlanRequired;
use NickDeKruijk\ListMailing\Exceptions\RateLimited;
use NickDeKruijk\ListMailing\Exceptions\ServerError;
use NickDeKruijk\ListMailing\Exceptions\SingleOptInNotEnabled;
use NickDeKruijk\ListMailing\Exceptions\Unauthenticated;
use NickDeKruijk\ListMailing\Exceptions\UnexpectedResponse;
use NickDeKruijk\ListMailing\Exceptions\ValidationFailed;
use NickDeKruijk\ListMailing\Laravel\ClientFake;
use NickDeKruijk\ListMailing\Laravel\ListMailing;
use NickDeKruijk\ListMailing\Laravel\SignUpFailed;
use NickDeKruijk\ListMailing\Laravel\SignUpProcessed;
use NickDeKruijk\ListMailing\Laravel\SubscribeToList;
use NickDeKruijk\ListMailing\SubscribeOutcome;
use NickDeKruijk\ListMailing\Subscription;

function booking(string $email = 'anna@example.com', ?string $reference = 'B-2026-0123', ?Confirmation $confirmation = null): Subscription
{
    return new Subscription($email, reference: $reference, consentedAt: new DateTimeImmutable, consentText: 'Ja, stuur mij de nieuwsbrief', confirmation: $confirmation);
}

function run(SubscribeToList $job): SubscribeToList
{
    $job->withFakeQueueInteractions();
    $job->handle();

    return $job;
}

beforeEach(function () {
    $this->fake = ListMailing::fake();
    Event::fake([SignUpProcessed::class, SignUpFailed::class]);
    Exceptions::fake();
});

it('signs up on the default list with its idempotency key', function () {
    $job = run(new SubscribeToList(booking()));

    $job->assertNotFailed();
    $job->assertNotReleased();
    $this->fake->assertSubscribed(fn (Subscription $s, string $list, ?string $key) => $s->email === 'anna@example.com'
        && $list === 'default-list'
        && $key === $job->idempotencyKey);
});

it('uses the list it was given', function () {
    run(new SubscribeToList(booking(), 'other-list'));

    $this->fake->assertSubscribed('anna@example.com', 'other-list');
    $this->fake->assertNotSubscribed('anna@example.com', 'default-list');
});

it('fires SignUpProcessed with the result', function (SubscribeOutcome $outcome) {
    $this->fake->respondWith($outcome);

    run(new SubscribeToList(booking(), 'list-9'));

    Event::assertDispatched(SignUpProcessed::class, fn (SignUpProcessed $event) => $event->result->outcome === $outcome
        && $event->listId === 'list-9'
        && $event->subscription->email === 'anna@example.com');
})->with(SubscribeOutcome::cases());

it('releases a retryable failure after Retry-After', function (Throwable $failure, int $delay) {
    $this->fake->respondWith($failure);

    $job = run(new SubscribeToList(booking()));

    $job->assertReleased(delay: $delay);
    $job->assertNotFailed();
    Event::assertNotDispatched(SignUpProcessed::class);
    Exceptions::assertNothingReported();
})->with([
    'rate limited' => [fn () => new RateLimited('Wait.', 'rate_limited', 429, 42), 42],
    'daily limit' => [fn () => new DailyLimitReached('Full.', 'daily_limit_reached', 429, 36000), 36000],
    'server error with retry after' => [fn () => new ServerError('Down.', null, 503, 120), 120],
    'server error without' => [fn () => new ServerError('Oops.', null, 500), 60],
    'timeout' => [fn () => new ConnectionFailed('Timed out.'), 60],
]);

it('backs off further on each attempt without Retry-After', function (int $attempts, int $delay) {
    $this->fake->respondWith(new ConnectionFailed('Timed out.'));

    $job = (new SubscribeToList(booking()))->withFakeQueueInteractions();
    $job->job->attempts = $attempts;
    $job->handle();

    $job->assertReleased(delay: $delay);
})->with([[1, 60], [2, 300], [3, 900], [4, 1800], [5, 3600], [12, 3600]]);

it('fails with the real reason once it would retry past its deadline', function () {
    $this->fake->respondWith(new DailyLimitReached('Full.', 'daily_limit_reached', 429, SubscribeToList::RETRY_HOURS * 3600 + 1));

    $job = run(new SubscribeToList(booking()));

    $job->assertNotReleased();
    $job->assertFailedWith(DailyLimitReached::class);
    Exceptions::assertReported(DailyLimitReached::class);
});

it('fails and reports any other error without a retry', function (Throwable $failure) {
    $this->fake->respondWith($failure);

    $job = run(new SubscribeToList(booking()));

    $job->assertFailedWith($failure::class);
    $job->assertNotReleased();
    Exceptions::assertReported($failure::class);
    Event::assertNotDispatched(SignUpProcessed::class);
})->with([
    'unauthenticated' => [fn () => new Unauthenticated('No.', 'unauthenticated', 401)],
    'plan required' => [fn () => new PlanRequired('No.', 'plan_required', 403)],
    'list not allowed' => [fn () => new ListNotAllowed('No.', 'list_not_allowed', 403)],
    'single opt-in not enabled' => [fn () => new SingleOptInNotEnabled('No.', 'single_opt_in_not_enabled', 403)],
    'https required' => [fn () => new HttpsRequired('No.', 'https_required', 403)],
    'not found' => [fn () => new NotFound('No.', 'not_found', 404)],
    'validation' => [fn () => new ValidationFailed('Bad email.', ['email' => ['Bad email.']])],
    'unexpected' => [fn () => new UnexpectedResponse('Huh.', null, 200)],
]);

it('fails without a list instead of throwing at dispatch', function () {
    config(['listmailing.list' => null]);

    $job = run(new SubscribeToList(booking()));

    $job->assertFailedWith(InvalidArgumentException::class);
    $this->fake->assertNothingSubscribed();
});

it('keeps its idempotency key across serialization', function () {
    $job = new SubscribeToList(booking(reference: null));

    $restored = unserialize(serialize($job));

    expect($restored->idempotencyKey)->toBe($job->idempotencyKey)
        ->and($restored->listId)->toBe('default-list')
        ->and($restored->subscription)->toEqual($job->subscription);
});

it('derives the key from list, address, confirmation and reference', function () {
    $key = fn (Subscription $s, string $list = 'list-1') => SubscribeToList::idempotencyKey($s, $list);

    expect($key(booking()))->toBe($key(booking('ANNA@example.com ')))
        ->and($key(booking()))->toBe($key(booking(confirmation: Confirmation::Email)))
        ->and($key(booking()))->not->toBe($key(booking(), 'list-2'))
        ->and($key(booking()))->not->toBe($key(booking('bob@example.com')))
        ->and($key(booking()))->not->toBe($key(booking(confirmation: Confirmation::Link)))
        ->and($key(booking()))->not->toBe($key(booking(reference: 'B-2026-0124')))
        ->and($key(booking(reference: null)))->not->toBe($key(booking(reference: null)));
});

it('derives the key the job sends when no list is given', function () {
    $subscription = booking();

    expect(SubscribeToList::idempotencyKey($subscription))->toBe(SubscribeToList::idempotencyKey($subscription, 'default-list'))
        ->and(SubscribeToList::idempotencyKey($subscription))->toBe((new SubscribeToList($subscription))->idempotencyKey)
        ->and(SubscribeToList::idempotencyKey($subscription, 'other-list'))->toBe((new SubscribeToList($subscription, 'other-list'))->idempotencyKey);
});

it('derives a key without a list in the config', function () {
    config(['listmailing.list' => null]);

    expect(SubscribeToList::idempotencyKey(booking()))->toBe(SubscribeToList::idempotencyKey(booking(), ''));
});

it('goes on the configured queue, after the commit', function () {
    Queue::fake();
    config(['listmailing.queue' => 'newsletter']);

    SubscribeToList::dispatch(booking());

    Queue::assertPushedOn('newsletter', SubscribeToList::class);
    expect(new SubscribeToList(booking()))->toBeInstanceOf(ShouldQueueAfterCommit::class);
});

it('retries for two days', function () {
    $job = new SubscribeToList(booking());

    expect($job->availableAt())->toBe($job->createdAt)
        ->and($job->retryUntil()->getTimestamp())->toBe($job->createdAt + SubscribeToList::RETRY_HOURS * 3600);
});

it('counts the retry period from the end of a delay', function (Closure $delay, int $seconds) {
    $job = (new SubscribeToList(booking()))->delay($delay());

    expect($job->availableAt() - $job->createdAt)->toBeGreaterThanOrEqual($seconds - 1)->toBeLessThanOrEqual($seconds + 1)
        ->and($job->retryUntil()->getTimestamp())->toBe($job->availableAt() + SubscribeToList::RETRY_HOURS * 3600);
})->with([
    'date' => [fn () => now()->addDay(), 86400],
    'seconds' => [fn () => 3600, 3600],
    'interval' => [fn () => new DateInterval('PT2H'), 7200],
    'in the past' => [fn () => now()->subHour(), 0],
]);

it('keeps retrying after a delay for the full period', function () {
    $wait = 50 * 3600;
    $this->fake->respondWith(
        new DailyLimitReached('Full.', 'daily_limit_reached', 429, $wait),
        new DailyLimitReached('Full.', 'daily_limit_reached', 429, $wait),
    );

    $delayed = run((new SubscribeToList(booking()))->delay(now()->addDay()));
    $now = run(new SubscribeToList(booking()));

    $delayed->assertReleased(delay: $wait);
    $now->assertFailedWith(DailyLimitReached::class);
});

it('fires SignUpFailed once when it gives up', function (Throwable $failure) {
    $this->fake->respondWith($failure);

    SubscribeToList::dispatchSync(booking(), 'list-9');

    Event::assertDispatchedTimes(SignUpFailed::class, 1);
    Event::assertDispatched(SignUpFailed::class, fn (SignUpFailed $event) => $event->exception === $failure
        && $event->listId === 'list-9'
        && $event->subscription->email === 'anna@example.com');
    Event::assertNotDispatched(SignUpProcessed::class);
})->with([
    'not retryable' => [fn () => new ListNotAllowed('No.', 'list_not_allowed', 403)],
    'past the retry period' => [fn () => new DailyLimitReached('Full.', 'daily_limit_reached', 429, SubscribeToList::RETRY_HOURS * 3600 + 1)],
]);

it('fires SignUpFailed without a list', function () {
    config(['listmailing.list' => null]);

    SubscribeToList::dispatchSync(booking());

    Event::assertDispatchedTimes(SignUpFailed::class, 1);
    Event::assertDispatched(SignUpFailed::class, fn (SignUpFailed $event) => $event->listId === null
        && $event->exception instanceof InvalidArgumentException);
});

it('fires SignUpFailed once when the worker gives up on the job', function () {
    $exception = new MaxAttemptsExceededException('SubscribeToList has been attempted too many times.');

    (new SubscribeToList(booking(), 'list-9'))->failed($exception);

    Event::assertDispatchedTimes(SignUpFailed::class, 1);
    Event::assertDispatched(SignUpFailed::class, fn (SignUpFailed $event) => $event->exception === $exception
        && $event->listId === 'list-9');
    $this->fake->assertNothingSubscribed();
});

it('fires SignUpFailed with a reason when failed without one', function () {
    (new SubscribeToList(booking()))->failed();

    Event::assertDispatched(SignUpFailed::class, fn (SignUpFailed $event) => $event->exception instanceof RuntimeException);
});

it('fires no SignUpFailed on a release or a success', function () {
    $this->fake->respondWith(new ConnectionFailed('Timed out.'));

    SubscribeToList::dispatchSync(booking());
    SubscribeToList::dispatchSync(booking());

    $this->fake->assertSubscribedTimes(2);
    Event::assertNotDispatched(SignUpFailed::class);
});

it('never throws on the sync queue', function () {
    config(['queue.default' => 'sync']);
    $this->fake->respondWith(new ListNotAllowed('No.', 'list_not_allowed', 403));

    SubscribeToList::dispatch(booking());

    $this->fake->assertSubscribedTimes(1);
    Exceptions::assertReported(ListNotAllowed::class);
});

it('is a ClientFake in tests', function () {
    expect(app(ClientInterface::class))->toBeInstanceOf(ClientFake::class);
});
