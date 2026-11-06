<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Exceptions;

use RuntimeException;
use Throwable;

class ItsSmsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $errorCode = 'unknown_error',
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
