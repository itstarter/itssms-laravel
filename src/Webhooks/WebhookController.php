<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ItsStarter\Sms\Data\WebhookEvent;
use ItsStarter\Sms\Webhooks\Events\MessageDelivered;
use ItsStarter\Sms\Webhooks\Events\MessageFailed;
use ItsStarter\Sms\Webhooks\Events\MessageSubmitted;

/**
 * Turns a verified webhook into a Laravel event and answers 200 straight away.
 * Unknown event types are acknowledged and ignored so new portal events never cause retries.
 */
final class WebhookController
{
    public function __invoke(Request $request, Dispatcher $events): JsonResponse
    {
        $event = $request->attributes->get(VerifyItsSmsSignature::EVENT_ATTRIBUTE);

        if (! $event instanceof WebhookEvent) {
            return new JsonResponse(['error' => ['code' => 'invalid_signature', 'message' => 'Webhook rejected.']], 401);
        }

        $class = match ($event->type) {
            'message.submitted' => MessageSubmitted::class,
            'message.delivered' => MessageDelivered::class,
            'message.failed' => MessageFailed::class,
            default => null,
        };

        if ($class !== null) {
            $events->dispatch(new $class($event));
        }

        return new JsonResponse(['received' => true]);
    }
}
