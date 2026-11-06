<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Exceptions;

use Throwable;

class ConflictException extends ItsSmsException
{
    public function __construct(
        string $message,
        int $status = 409,
        string $errorCode = 'idempotency_conflict',
        ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $errorCode, $requestId, $previous);
    }
}
