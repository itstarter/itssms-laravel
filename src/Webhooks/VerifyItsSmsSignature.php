<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Webhooks;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use ItsStarter\Sms\Exceptions\ConfigurationException;
use ItsStarter\Sms\Exceptions\WebhookException;
use ItsStarter\Sms\SmsManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects webhook calls that are not signed by ITS SMS. Answers 401 on any failure,
 * with a generic body that reveals nothing about the secret or the expected signature.
 */
final class VerifyItsSmsSignature
{
    public const EVENT_ATTRIBUTE = 'itssms.webhook_event';

    public function __construct(private readonly SmsManager $manager) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $request->attributes->set(self::EVENT_ATTRIBUTE, $this->manager->verifyWebhook($request));
        } catch (WebhookException $e) {
            return $this->reject($e->status, $e->errorCode);
        } catch (ConfigurationException) {
            Log::warning('ITS SMS webhook rejected: webhook secret is not configured.');

            return $this->reject(401, 'invalid_signature');
        }

        return $next($request);
    }

    private function reject(int $status, string $code): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => 'Webhook rejected.']], $status);
    }
}
