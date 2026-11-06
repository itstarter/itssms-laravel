<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Tests;

abstract class NoWebhookTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('itssms.webhook.path', null);
    }
}
