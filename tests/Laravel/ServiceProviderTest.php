<?php

use NickDeKruijk\ListMailing\Client;
use NickDeKruijk\ListMailing\ClientInterface;
use NickDeKruijk\ListMailing\Laravel\ListMailing;
use NickDeKruijk\ListMailing\Subscription;

it('builds the client from the config', function () {
    config([
        'listmailing.base_url' => 'https://listmailing.test/api/v1',
        'listmailing.list' => 'list-from-config',
        'listmailing.timeout' => 3,
    ]);

    $client = app(ClientInterface::class);

    expect($client)->toBeInstanceOf(Client::class)
        ->and($client->baseUrl)->toBe('https://listmailing.test/api/v1')
        ->and($client->defaultList)->toBe('list-from-config')
        ->and($client->timeout)->toBe(3.0)
        ->and(app(ClientInterface::class))->toBe($client);
});

it('falls back to listmailing.nl and no default list', function () {
    config(['listmailing.base_url' => null, 'listmailing.list' => '']);

    $client = app(ClientInterface::class);

    expect($client->baseUrl)->toBe(Client::DEFAULT_BASE_URL)
        ->and($client->defaultList)->toBeNull();
});

it('boots without a token and fails only on use', function () {
    config(['listmailing.token' => null]);

    expect(fn () => app(ClientInterface::class))->toThrow(InvalidArgumentException::class, 'token');
});

it('has the defaults in the merged config', function () {
    expect(config('listmailing.base_url'))->toBe('https://listmailing.nl/api/v1')
        ->and(config('listmailing.timeout'))->toBe(10.0);
});

it('lets the facade use the default list', function () {
    $fake = ListMailing::fake();

    ListMailing::subscribe(new Subscription('anna@example.com'));
    ListMailing::status('anna@example.com');

    $fake->assertSubscribed('anna@example.com', 'default-list');
    $fake->assertStatusChecked('anna@example.com', 'default-list');
    expect(app(ClientInterface::class))->toBe($fake);
});

it('gives a client with a shorter timeout through the facade', function () {
    $client = ListMailing::withTimeout(3);

    expect($client)->toBeInstanceOf(Client::class)
        ->and($client->timeout)->toBe(3.0)
        ->and($client->defaultList)->toBe('default-list')
        ->and(app(ClientInterface::class)->timeout)->toBe(10.0);
});
