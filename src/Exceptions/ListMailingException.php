<?php

namespace NickDeKruijk\ListMailing\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Everything the client throws about a request. Catch this to catch them all.
 */
class ListMailingException extends RuntimeException
{
    /**
     * @param  string|null  $errorCode  the fixed code in the error field of the answer, such as list_not_allowed
     * @param  int|null  $httpStatus  null when no answer came back
     */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }
}
