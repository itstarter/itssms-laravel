<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Exceptions;

use Throwable;

class ConfigurationException extends ItsSmsException
{
    public function __construct(
        string $message,
        int $status = 0,
        string $errorCode = 'missing_api_key',
        ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $errorCode, $requestId, $previous);
    }
}
