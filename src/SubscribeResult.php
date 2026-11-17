<?php

namespace NickDeKruijk\ListMailing;

use NickDeKruijk\ListMailing\Exceptions\UnexpectedResponse;

/**
 * The answer to a sign-up.
 */
final readonly class SubscribeResult
{
    /**
     * @param  string|null  $subscriberId  ListMailing's id of the contact, null until the address is confirmed
     * @param  string|null  $confirmationUrl  only with Confirmation::Link: the link to put in your own mail, valid for 30 days
     * @param  bool  $created  true when ListMailing answered 201: an address new to this list
     */
    public function __construct(
        public SubscribeOutcome $outcome,
        public ?string $subscriberId = null,
        public ?string $confirmationUrl = null,
        public bool $created = false,
    ) {}

    /**
     * @param  array<mixed>  $body
     *
     * @throws UnexpectedResponse
     */
    public static function fromResponse(array $body, int $httpStatus): self
    {
        $outcome = is_string($body['status'] ?? null) ? SubscribeOutcome::tryFrom($body['status']) : null;

        if ($outcome === null) {
            throw new UnexpectedResponse('ListMailing answered with an unknown sign-up status.', null, $httpStatus);
        }

        return new self(
            outcome: $outcome,
            subscriberId: is_string($body['subscriber_id'] ?? null) ? $body['subscriber_id'] : null,
            confirmationUrl: is_string($body['confirmation_url'] ?? null) ? $body['confirmation_url'] : null,
            created: $httpStatus === 201,
        );
    }
}
