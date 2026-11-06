<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Webhooks\Events;

use ItsStarter\Sms\Data\WebhookEvent;

abstract class MessageEvent
{
    public function __construct(public readonly WebhookEvent $event) {}
}
