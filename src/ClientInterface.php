<?php

namespace NickDeKruijk\ListMailing;

use InvalidArgumentException;
use NickDeKruijk\ListMailing\Exceptions\ListMailingException;

/**
 * The sign-up API. Type-hint this, not Client, so a fake can take its place.
 */
interface ClientInterface
{
    /**
     * Sign an address up for a list.
     *
     * @param  string|null  $listId  null uses the default list
     * @param  string|null  $idempotencyKey  the same key within 24 hours returns the same answer and does nothing again
     *
     * @throws ListMailingException
     * @throws InvalidArgumentException when there is no list id and no default list
     */
    public function subscribe(Subscription $subscription, ?string $listId = null, ?string $idempotencyKey = null): SubscribeResult;

    /**
     * Where an address stands on a list.
     *
     * @param  string|null  $listId  null uses the default list
     *
     * @throws ListMailingException
     * @throws InvalidArgumentException when there is no list id and no default list
     */
    public function status(string $email, ?string $listId = null): StatusResult;
}
