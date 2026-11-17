<?php

use NickDeKruijk\ListMailing\SubscribeOutcome;

it('knows which outcomes are on the list and which await confirmation', function (SubscribeOutcome $outcome, bool $onList, bool $awaits) {
    expect($outcome->isOnList())->toBe($onList)
        ->and($outcome->awaitsConfirmation())->toBe($awaits);
})->with([
    'pending' => [SubscribeOutcome::Pending, false, true],
    'already subscribed' => [SubscribeOutcome::AlreadySubscribed, true, false],
    'resubscribe pending' => [SubscribeOutcome::ResubscribePending, false, true],
    'subscribed' => [SubscribeOutcome::Subscribed, true, false],
    'suppressed' => [SubscribeOutcome::Suppressed, false, false],
]);
