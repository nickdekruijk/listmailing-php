<?php

namespace NickDeKruijk\ListMailing\Exceptions;

use Throwable;

/**
 * A failure that may well go away: a timeout, an error on ListMailing's side
 * or a limit. Try the same request again later, with the same Idempotency-Key.
 */
interface Retryable extends Throwable
{
    /** The seconds to wait from the Retry-After header, or null when the answer gave none. */
    public function retryAfter(): ?int;
}
