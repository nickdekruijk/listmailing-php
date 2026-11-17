<?php

namespace NickDeKruijk\ListMailing;

use NickDeKruijk\ListMailing\Exceptions\UnexpectedResponse;

/**
 * The answer to a status lookup.
 */
final readonly class StatusResult
{
    /**
     * @param  string|null  $subscriberId  ListMailing's id of the contact, null when the address is not stored
     */
    public function __construct(
        public MembershipStatus $status,
        public ?string $subscriberId = null,
    ) {}

    /**
     * @param  array<mixed>  $body
     *
     * @throws UnexpectedResponse
     */
    public static function fromResponse(array $body, int $httpStatus): self
    {
        $status = is_string($body['status'] ?? null) ? MembershipStatus::tryFrom($body['status']) : null;

        if ($status === null) {
            throw new UnexpectedResponse('ListMailing answered with an unknown membership status.', null, $httpStatus);
        }

        return new self(
            status: $status,
            subscriberId: is_string($body['subscriber_id'] ?? null) ? $body['subscriber_id'] : null,
        );
    }
}
