<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Webhooks;

use ItsStarter\Sms\Exceptions\ConfigurationException;
use ItsStarter\Sms\Exceptions\WebhookException;

/**
 * Checks X-ITS-Timestamp and X-ITS-Signature: "sha256=" . hex(HMAC-SHA256(secret, "<timestamp>.<raw body>")).
 */
final class WebhookVerifier
{
    /**
     * @throws ConfigurationException when no secret is configured
     * @throws WebhookException when the call cannot be trusted
     */
    public static function verify(
        string $body,
        ?string $timestamp,
        ?string $signature,
        ?string $secret,
        int $tolerance,
        ?int $now = null,
    ): void {
        if ($secret === null || $secret === '') {
            throw new ConfigurationException(
                'No ITS SMS webhook secret configured. Set ITSSMS_WEBHOOK_SECRET.',
                0,
                'missing_webhook_secret',
            );
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signature === null || $signature === '') {
            throw new WebhookException('Missing or malformed webhook signature headers.');
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            throw new WebhookException('The webhook timestamp is outside the allowed tolerance.', 401, 'stale_timestamp');
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        if (! hash_equals($expected, strtolower(trim($signature)))) {
            throw new WebhookException('The webhook signature does not match.');
        }
    }
}
