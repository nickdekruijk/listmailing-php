<?php

namespace NickDeKruijk\ListMailing\Laravel;

use Illuminate\Support\Facades\Facade;
use NickDeKruijk\ListMailing\ClientInterface;
use NickDeKruijk\ListMailing\StatusResult;
use NickDeKruijk\ListMailing\SubscribeResult;
use NickDeKruijk\ListMailing\Subscription;

/**
 * @method static SubscribeResult subscribe(Subscription $subscription, ?string $listId = null, ?string $idempotencyKey = null)
 * @method static StatusResult status(string $email, ?string $listId = null)
 *
 * @see ClientInterface
 */
class ListMailing extends Facade
{
    /**
     * Replace the client with a fake that records every call, for the tests of
     * your site. The SubscribeToList job uses it too.
     */
    public static function fake(): ClientFake
    {
        $list = config('listmailing.list');
        $fake = new ClientFake(is_string($list) && $list !== '' ? $list : null);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return ClientInterface::class;
    }
}
