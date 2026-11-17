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
     * @param  string|null  $status  the status as ListMailing sent it; the way to read an outcome of Unknown
     */
    public function __construct(
        public SubscribeOutcome $outcome,
        public ?string $subscriberId = null,
        public ?string $confirmationUrl = null,
        public bool $created = false,
        public ?string $status = null,
    ) {}

    /**
     * @param  array<mixed>  $body
     *
     * @throws UnexpectedResponse
     */
    public static function fromResponse(array $body, int $httpStatus): self
    {
        $status = is_string($body['status'] ?? null) ? $body['status'] : null;

        if ($status === null || $status === '') {
            throw new UnexpectedResponse('ListMailing answered without a sign-up status.', null, $httpStatus);
        }

        // A status added to the API after this version was written is still
        // the answer to a sign-up that went through. Throwing here would fail
        // it for good on every site that has not updated the package yet.
        return new self(
            status: $status,
            outcome: SubscribeOutcome::tryFrom($status) ?? SubscribeOutcome::Unknown,
            subscriberId: is_string($body['subscriber_id'] ?? null) ? $body['subscriber_id'] : null,
            confirmationUrl: is_string($body['confirmation_url'] ?? null) ? $body['confirmation_url'] : null,
            created: $httpStatus === 201,
        );
    }
}
