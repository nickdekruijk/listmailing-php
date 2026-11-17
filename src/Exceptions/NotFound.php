<?php

namespace NickDeKruijk\ListMailing\Exceptions;

/**
 * HTTP 404: the list does not exist, or does not belong to the account of the token.
 */
class NotFound extends ListMailingException {}
