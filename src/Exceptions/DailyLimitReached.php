<?php

namespace NickDeKruijk\ListMailing\Exceptions;

/**
 * HTTP 429: the sign-ups for today are used up. retryAfter runs until midnight UTC.
 */
class DailyLimitReached extends TooManyRequests {}
