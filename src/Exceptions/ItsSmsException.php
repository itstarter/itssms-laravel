<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Exceptions;

use ItsStarter\Sms\Data\SendResult;
use RuntimeException;
use Throwable;

class ItsSmsException extends RuntimeException
{
    /**
     * Results of the batches accepted before this one failed (a send to more than 1,000
     * numbers). Set by Client only; empty for every other error.
     *
     * @var list<SendResult>
     */
    public array $sentResults = [];

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
