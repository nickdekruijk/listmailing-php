<?php

namespace NickDeKruijk\ListMailing\Exceptions;

use Throwable;

/**
 * HTTP 5xx: something went wrong on ListMailing's side, or it is down for
 * maintenance. Try again later.
 */
class ServerError extends ListMailingException implements Retryable
{
    public function __construct(
        string $message,
        ?string $errorCode = null,
        ?int $httpStatus = 500,
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
