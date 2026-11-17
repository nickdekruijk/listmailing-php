<?php

namespace NickDeKruijk\ListMailing\Exceptions;

/**
 * No answer came back: a timeout, a DNS failure or a refused connection. The
 * request may or may not have arrived, so try again with the same
 * Idempotency-Key. The message names the Guzzle exception and its reason,
 * with the email address left out. The Guzzle exception is not kept as the
 * previous one, because its message holds the full URL with the address.
 */
class ConnectionFailed extends ListMailingException implements Retryable
{
    public function retryAfter(): ?int
    {
        return null;
    }
}
