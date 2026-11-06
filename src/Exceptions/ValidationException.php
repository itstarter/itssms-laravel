<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Exceptions;

use Throwable;

class ValidationException extends ItsSmsException
{
    /**
     * @param  list<string>  $invalidNumbers  Numbers rejected locally (or reported by the API).
     */
    public function __construct(
        string $message,
        int $status = 422,
        string $errorCode = 'validation_failed',
        ?string $requestId = null,
        ?Throwable $previous = null,
        public readonly array $invalidNumbers = [],
    ) {
        parent::__construct($message, $status, $errorCode, $requestId, $previous);
    }
}
