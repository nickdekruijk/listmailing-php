<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use NickDeKruijk\ListMailing\Exceptions\DailyLimitReached;
use NickDeKruijk\ListMailing\Laravel\ListMailing;
use NickDeKruijk\ListMailing\Laravel\SignUpProcessed;
use NickDeKruijk\ListMailing\Laravel\SubscribeToList;
use NickDeKruijk\ListMailing\Subscription;

/*
 * Real listeners here: the other tests of the job fake its events, which is
 * exactly what hides a listener that throws.
 */
beforeEach(function () {
    $this->fake = ListMailing::fake();
    Exceptions::fake();
});

it('survives a listener that throws: the sign-up went through, so nothing is tried again', function () {
    Event::listen(SignUpProcessed::class, fn () => throw new RuntimeException('The booking table is locked.'));

    $job = new SubscribeToList(new Subscription('anna@example.com', reference: 'B-1'));
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased();
    $job->assertNotFailed();
    $this->fake->assertSubscribedTimes(1);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'The booking table is locked.');
});

it('never throws on the sync queue, also when a listener does', function () {
    Event::listen(SignUpProcessed::class, fn () => throw new RuntimeException('The booking table is locked.'));

    SubscribeToList::dispatchSync(new Subscription('anna@example.com', reference: 'B-1'));

    $this->fake->assertSubscribedTimes(1);
});

it('tells the worker to wait between tries when something outside handle() throws', function () {
    expect((new SubscribeToList(new Subscription('anna@example.com')))->backoff())->toBe(SubscribeToList::BACKOFF);
});

it('never asks a queue to wait longer than twelve hours', function () {
    // A full day can be 24 hours away; SQS refuses a delay of more than 12.
    $this->fake->respondWith(new DailyLimitReached('Full.', 'daily_limit_reached', 429, 80000));

    $job = new SubscribeToList(new Subscription('anna@example.com', reference: 'B-1'));
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased(delay: 43200);
});

it('reads the clock Laravel reads, so a test that freezes time sees one moment', function () {
    $this->travelTo('2030-01-01 12:00:00');

    $job = new SubscribeToList(new Subscription('anna@example.com'));

    expect($job->createdAt)->toBe(now()->getTimestamp())
        ->and($job->retryUntil()->getTimestamp())->toBe(now()->addHours(SubscribeToList::RETRY_HOURS)->getTimestamp());
});
