<?php

use ItsStarter\Sms\Tests\NoWebhookTestCase;
use ItsStarter\Sms\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(NoWebhookTestCase::class)->in('Isolated');
