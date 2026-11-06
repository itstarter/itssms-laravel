<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Exceptions;

use Throwable;

/**
 * A webhook call could not be trusted (bad signature, stale timestamp) or understood (bad body).
 */
class WebhookException extends ItsSmsException
{
    public function __construct(
        string $message,
        int $status = 401,
        string $errorCode = 'invalid_signature',
        ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $errorCode, $requestId, $previous);
    }
}
