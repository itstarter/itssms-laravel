<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Exceptions;

use Throwable;

class CreditException extends ItsSmsException
{
    public function __construct(
        string $message,
        int $status = 402,
        string $errorCode = 'insufficient_credits',
        ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $errorCode, $requestId, $previous);
    }
}
