<?php

namespace NickDeKruijk\ListMailing\Exceptions;

use Throwable;

/**
 * HTTP 429: a limit is reached. Wait the seconds in retryAfter.
 */
abstract class TooManyRequests extends ListMailingException implements Retryable
{
    public function __construct(
        string $message,
        ?string $errorCode = null,
        ?int $httpStatus = 429,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $httpStatus, $previous);
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
