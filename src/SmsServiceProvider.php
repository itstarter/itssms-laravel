<?php

declare(strict_types=1);

namespace ItsStarter\Sms;

use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ItsStarter\Sms\Notifications\ItsSmsChannel;
use ItsStarter\Sms\Webhooks\VerifyItsSmsSignature;
use ItsStarter\Sms\Webhooks\WebhookController;

class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/itssms.php', 'itssms');

        $this->app->singleton(SmsManager::class, fn ($app) => new SmsManager($app));
        $this->app->alias(SmsManager::class, 'itssms');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/itssms.php' => $this->app->configPath('itssms.php'),
            ], 'itssms-config');
        }

        // Lets a notification use via(): ['itssms'] as well as ItsSmsChannel::class.
        $this->callAfterResolving(ChannelManager::class, function (ChannelManager $manager): void {
            $manager->extend('itssms', fn ($app) => $app->make(ItsSmsChannel::class));
        });

        $this->registerWebhookRoute();
    }

    private function registerWebhookRoute(): void
    {
        $path = config('itssms.webhook.path');

        if (! is_string($path) || trim($path, '/') === '' || $this->app->routesAreCached()) {
            return;
        }

        Route::post(trim($path, '/'), WebhookController::class)
            ->middleware(VerifyItsSmsSignature::class)
            ->name('itssms.webhook');
    }
}
