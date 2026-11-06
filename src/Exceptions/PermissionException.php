<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Exceptions;

use Throwable;

class PermissionException extends ItsSmsException
{
    public function __construct(
        string $message,
        int $status = 403,
        string $errorCode = 'account_suspended',
        ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $errorCode, $requestId, $previous);
    }
}
