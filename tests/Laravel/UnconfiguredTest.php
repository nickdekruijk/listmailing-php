<?php

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use NickDeKruijk\ListMailing\Laravel\ListMailing;
use NickDeKruijk\ListMailing\Laravel\SignUpFailed;
use NickDeKruijk\ListMailing\Laravel\SubscribeToList;
use NickDeKruijk\ListMailing\Subscription;

it('fails the job without a token instead of breaking the request', function () {
    config(['listmailing.token' => '']);
    Exceptions::fake();
    Event::fake([SignUpFailed::class, JobFailed::class]);

    SubscribeToList::dispatchSync(new Subscription('anna@example.com', reference: 'B-1'));

    Exceptions::assertReported(fn (InvalidArgumentException $e) => str_contains($e->getMessage(), 'token'));
    Event::assertDispatched(SignUpFailed::class, fn (SignUpFailed $event) => $event->exception instanceof InvalidArgumentException);
    Event::assertDispatched(JobFailed::class, fn (JobFailed $event) => $event->exception instanceof InvalidArgumentException);
});

it('says whether a token and a list are set', function (mixed $token, mixed $list, bool $configured) {
    config(['listmailing.token' => $token, 'listmailing.list' => $list]);

    expect(ListMailing::configured())->toBe($configured);
})->with([
    'both' => ['lm_token', 'list-1', true],
    'no token' => [null, 'list-1', false],
    'empty token' => [' ', 'list-1', false],
    'no list' => ['lm_token', null, false],
    'empty list' => ['lm_token', '', false],
]);
