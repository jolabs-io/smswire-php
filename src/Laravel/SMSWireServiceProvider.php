<?php

declare(strict_types=1);

namespace SMSWire\Laravel;

use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;
use SMSWire\Client;

/** Auto-discovered: binds the Client, the 'smswire' notification channel and the config. */
final class SMSWireServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/smswire.php', 'smswire');

        $this->app->singleton(Client::class, function ($app) {
            $config = $app['config']['smswire'];
            return new Client($config['key'] ?? null, $config['project'] ?? null, array_filter([
                'base_url' => $config['url'] ?? null,
                'webhook_secret' => $config['webhook_secret'] ?? null,
                'max_retries' => $config['max_retries'] ?? null,
                'timeout' => $config['timeout'] ?? null,
            ], static fn ($v) => $v !== null));
        });
        $this->app->alias(Client::class, 'smswire');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/smswire.php' => $this->app->configPath('smswire.php')], 'smswire-config');
        }

        Notification::resolved(static function (ChannelManager $channels): void {
            $channels->extend('smswire', static fn ($app) => $app->make(SMSWireChannel::class));
        });

        $this->app['router']->aliasMiddleware('smswire.signature', VerifySMSWireSignature::class);
    }
}
