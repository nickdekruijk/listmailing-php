<?php

use NickDeKruijk\ListMailing\Confirmation;
use NickDeKruijk\ListMailing\Exceptions\DailyLimitReached;
use NickDeKruijk\ListMailing\Laravel\ListMailing;
use NickDeKruijk\ListMailing\MembershipStatus;
use NickDeKruijk\ListMailing\StatusResult;
use NickDeKruijk\ListMailing\SubscribeOutcome;
use NickDeKruijk\ListMailing\SubscribeResult;
use NickDeKruijk\ListMailing\Subscription;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function () {
    $this->fake = ListMailing::fake();
});

it('answers pending by default', function () {
    $result = ListMailing::subscribe(new Subscription('anna@example.com'));

    expect($result->outcome)->toBe(SubscribeOutcome::Pending)
        ->and($result->created)->toBeTrue()
        ->and($result->confirmationUrl)->toBeNull();
});

it('hands back a confirmation url for a link', function () {
    $result = ListMailing::subscribe(new Subscription('anna@example.com', confirmation: Confirmation::Link));

    expect($result->confirmationUrl)->toStartWith('https://');
});

it('gives the answers it was told, in order, then pending', function () {
    $this->fake->respondWith(
        SubscribeOutcome::AlreadySubscribed,
        new SubscribeResult(SubscribeOutcome::Subscribed, 'sub_1', null, true),
        new DailyLimitReached('Full.', 'daily_limit_reached', 429, 60),
    );

    expect(ListMailing::subscribe(new Subscription('a@example.com'))->outcome)->toBe(SubscribeOutcome::AlreadySubscribed)
        ->and(ListMailing::subscribe(new Subscription('b@example.com'))->subscriberId)->toBe('sub_1')
        ->and(fn () => ListMailing::subscribe(new Subscription('c@example.com')))->toThrow(DailyLimitReached::class)
        ->and(ListMailing::subscribe(new Subscription('d@example.com'))->outcome)->toBe(SubscribeOutcome::Pending);

    $this->fake->assertSubscribedTimes(4);
});

it('answers status lookups', function () {
    $this->fake->statusWith(MembershipStatus::Subscribed, new StatusResult(MembershipStatus::Suppressed, 'sub_1'));

    expect(ListMailing::status('a@example.com')->status)->toBe(MembershipStatus::Subscribed)
        ->and(ListMailing::status('a@example.com')->subscriberId)->toBe('sub_1')
        ->and(ListMailing::status('a@example.com')->status)->toBe(MembershipStatus::NotSubscribed);
});

it('passes its asserts', function () {
    ListMailing::subscribe(new Subscription('Anna@Example.com', reference: 'B-1'), 'list-9', 'key-1');

    $this->fake->assertSubscribed('anna@example.com');
    $this->fake->assertSubscribed('anna@example.com', 'list-9');
    $this->fake->assertSubscribed(fn (Subscription $s, string $list, ?string $key) => $s->reference === 'B-1' && $key === 'key-1');
    $this->fake->assertNotSubscribed('bob@example.com');
    $this->fake->assertNotSubscribed('anna@example.com', 'default-list');
    $this->fake->assertSubscribedTimes(1);

    expect($this->fake->subscriptions())->toHaveCount(1)
        ->and($this->fake->subscriptions()[0]['listId'])->toBe('list-9');
});

it('fails its asserts', function (Closure $assert) {
    ListMailing::subscribe(new Subscription('anna@example.com'));

    expect(fn () => $assert($this->fake))->toThrow(AssertionFailedError::class);
})->with([
    'subscribed, other address' => [fn ($fake) => $fake->assertSubscribed('bob@example.com')],
    'subscribed, other list' => [fn ($fake) => $fake->assertSubscribed('anna@example.com', 'list-9')],
    'subscribed, callback' => [fn ($fake) => $fake->assertSubscribed(fn () => false)],
    'not subscribed' => [fn ($fake) => $fake->assertNotSubscribed('anna@example.com')],
    'nothing subscribed' => [fn ($fake) => $fake->assertNothingSubscribed()],
    'times' => [fn ($fake) => $fake->assertSubscribedTimes(2)],
    'status checked' => [fn ($fake) => $fake->assertStatusChecked('anna@example.com')],
]);
