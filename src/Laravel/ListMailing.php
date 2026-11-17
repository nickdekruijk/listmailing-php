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
     * Whether a token and a default list are set, so a site can leave the
     * newsletter box out or skip the sign-up instead of failing. Only reads
     * the config: it builds no client and does not call the API.
     */
    public static function configured(): bool
    {
        foreach (['listmailing.token', 'listmailing.list'] as $key) {
            $value = config($key);

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

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
