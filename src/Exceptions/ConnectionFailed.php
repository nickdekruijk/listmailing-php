<?php

namespace NickDeKruijk\ListMailing\Exceptions;

/**
 * No answer came back: a timeout, a DNS failure or a refused connection. The
 * request may or may not have arrived, so try again with the same
 * Idempotency-Key.
 */
class ConnectionFailed extends ListMailingException implements Retryable
{
    public function retryAfter(): ?int
    {
        return null;
    }
}
