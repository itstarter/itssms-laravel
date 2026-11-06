<?php

declare(strict_types=1);

namespace ItsStarter\Sms;

use Illuminate\Support\ServiceProvider;

class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/itssms.php', 'itssms');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/itssms.php' => $this->app->configPath('itssms.php'),
            ], 'itssms-config');
        }
    }
}
