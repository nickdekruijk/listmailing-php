<?php

namespace NickDeKruijk\ListMailing\Laravel;

use NickDeKruijk\ListMailing\Subscription;
use Throwable;

/**
 * Fired by SubscribeToList when it gives up: an error that will not go away
 * by trying again (a revoked token, a list the token may not use, a wrong
 * field, a missing token or list id), or a retryable one past the retry
 * period. Not fired when the job is only released to try again later.
 */
class SignUpFailed
{
    /**
     * @param  string|null  $listId  null when no list id was given and none is configured
     */
    public function __construct(
        public readonly Subscription $subscription,
        public readonly ?string $listId,
        public readonly Throwable $exception,
    ) {}
}
