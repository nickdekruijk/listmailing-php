<?php

namespace NickDeKruijk\ListMailing\Exceptions;

use Throwable;

/**
 * HTTP 422: a field is wrong. errors() names what is wrong per field.
 */
class ValidationFailed extends ListMailingException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, null, 422, $previous);
    }

    /**
     * The messages per field, such as ['email' => ['The email field must be a valid email address.']].
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
