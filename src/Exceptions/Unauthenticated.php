<?php

namespace NickDeKruijk\ListMailing\Exceptions;

/**
 * HTTP 401: no token, an unknown token or a revoked token.
 */
class Unauthenticated extends ListMailingException {}
