<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Exceptions;

use Throwable;

class ServerException extends ItsSmsException
{
    public function __construct(
        string $message,
        int $status = 500,
        string $errorCode = 'server_error',
        ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $errorCode, $requestId, $previous);
    }
}
