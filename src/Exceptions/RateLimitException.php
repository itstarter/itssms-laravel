<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Exceptions;

use Throwable;

class RateLimitException extends ItsSmsException
{
    public function __construct(
        string $message,
        int $status = 429,
        string $errorCode = 'rate_limited',
        ?string $requestId = null,
        ?Throwable $previous = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status, $errorCode, $requestId, $previous);
    }
}
