<?php

namespace NickDeKruijk\ListMailing\Laravel;

use NickDeKruijk\ListMailing\SubscribeResult;
use NickDeKruijk\ListMailing\Subscription;

/**
 * Fired by SubscribeToList after ListMailing answered. Listen for it to store
 * the outcome, or to handle SubscribeOutcome::Suppressed: that address only
 * gets on the list through a confirmation link in a mail of your own.
 */
class SignUpProcessed
{
    public function __construct(
        public readonly Subscription $subscription,
        public readonly string $listId,
        public readonly SubscribeResult $result,
    ) {}
}
