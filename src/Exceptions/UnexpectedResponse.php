<?php

namespace NickDeKruijk\ListMailing\Exceptions;

/**
 * An answer the client does not understand: no JSON, a status or an error code it does not know.
 */
class UnexpectedResponse extends ListMailingException {}
