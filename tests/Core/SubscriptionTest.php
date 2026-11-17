<?php

use NickDeKruijk\ListMailing\Confirmation;
use NickDeKruijk\ListMailing\Subscription;

it('keeps the consent time as an immutable copy', function () {
    $ticked = new DateTime('2026-11-17 10:15:00+01:00');
    $subscription = new Subscription('anna@example.com', consentedAt: $ticked);

    $ticked->modify('+1 day');

    expect($subscription->consentedAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($subscription->toArray()['consented_at'])->toBe('2026-11-17T10:15:00+01:00');
});

it('changes only the confirmation', function () {
    $subscription = new Subscription('anna@example.com', firstName: 'Anna', reference: 'B-1', confirmation: Confirmation::None);

    $link = $subscription->withConfirmation(Confirmation::Link);

    expect($link->confirmation)->toBe(Confirmation::Link)
        ->and($link->toArray())->toBe([...$subscription->toArray(), 'confirmation' => 'link'])
        ->and($subscription->confirmation)->toBe(Confirmation::None);
});

it('survives serialization for the queue', function () {
    $subscription = new Subscription('anna@example.com', consentedAt: new DateTimeImmutable('2026-11-17T10:15:00+01:00'), confirmation: Confirmation::Link);

    expect(unserialize(serialize($subscription)))->toEqual($subscription);
});

it('turns the locale into one the API takes', function (?string $given, ?string $sent) {
    expect((new Subscription('anna@example.com', locale: $given))->locale)->toBe($sent);
})->with([
    'nl' => ['nl', 'nl'],
    'upper case' => ['EN', 'en'],
    'with a region' => ['nl_NL', 'nl'],
    'with a dash and mixed case' => ['EN-gb', 'en'],
    'unknown' => ['de', null],
    'unknown with a region' => ['de_DE', null],
    'empty' => ['', null],
    'none' => [null, null],
]);

it('leaves an unknown locale out of the request, so the page language is used', function () {
    $subscription = new Subscription('anna@example.com', locale: 'de');

    expect($subscription->toArray())->not->toHaveKey('locale')
        ->and($subscription->withConfirmation(Confirmation::Link)->locale)->toBeNull()
        ->and(unserialize(serialize(new Subscription('anna@example.com', locale: 'nl_NL')))->locale)->toBe('nl');
});
