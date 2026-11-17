<?php

namespace NickDeKruijk\ListMailing\Exceptions;

/**
 * HTTP 429: too many requests per minute for this token.
 */
class RateLimited extends TooManyRequests {}
