<?php

namespace NickDeKruijk\ListMailing\Tests;

use NickDeKruijk\ListMailing\Laravel\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('listmailing.token', 'lm_test_token');
        $app['config']->set('listmailing.list', 'default-list');
    }
}
