<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Tests;

use ItsStarter\Sms\SmsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SmsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('itssms.api_key', 'itssms_live_DEFAULTKEY');
        $app['config']->set('itssms.base_url', 'https://sms.example.test/api/v1');
        $app['config']->set('itssms.webhook.secret', 'whsec_test');
        $app['config']->set('itssms.webhook.path', 'itssms/webhook');
        $app['config']->set('itssms.accounts', [
            'teamcomrade' => ['api_key' => 'itssms_live_TEAMKEY', 'sender' => 'TeamComrade'],
        ]);
    }
}
