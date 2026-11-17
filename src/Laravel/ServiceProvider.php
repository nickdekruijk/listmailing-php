<?php

namespace NickDeKruijk\ListMailing\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use NickDeKruijk\ListMailing\Client;
use NickDeKruijk\ListMailing\ClientInterface;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/listmailing.php', 'listmailing');

        // Built on first use, so a missing token only fails where the API is
        // called, not on every request of the site.
        $this->app->singleton(ClientInterface::class, function (Application $app): Client {
            $config = $app->make('config');
            $baseUrl = $config->get('listmailing.base_url');
            $list = $config->get('listmailing.list');
            $timeout = $config->get('listmailing.timeout');
            $locale = $config->get('listmailing.locale');

            return new Client(
                token: (string) $config->get('listmailing.token'),
                baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : Client::DEFAULT_BASE_URL,
                defaultList: is_string($list) && $list !== '' ? $list : null,
                // An empty LISTMAILING_TIMEOUT, as copied from .env.example, is
                // 0 after the cast in the config. The client refuses 0, which
                // would stop every sign-up, so the config falls back instead.
                timeout: is_numeric($timeout) && (float) $timeout > 0 ? (float) $timeout : Client::DEFAULT_TIMEOUT,
                defaultLocale: is_string($locale) ? $locale : null,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/listmailing.php' => config_path('listmailing.php'),
            ], 'listmailing-config');
        }
    }
}
