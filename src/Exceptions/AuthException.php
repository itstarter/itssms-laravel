<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Exceptions;

use Throwable;

class AuthException extends ItsSmsException
{
    public function __construct(
        string $message,
        int $status = 401,
        string $errorCode = 'invalid_api_key',
        ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $errorCode, $requestId, $previous);
    }
}
