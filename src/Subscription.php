<?php

namespace NickDeKruijk\ListMailing;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * One sign-up: the address and what you know about the consent. Send the
 * source, reference, time and consent text whenever you have them, so the
 * consent can be shown later.
 */
final readonly class Subscription
{
    /** When the box was ticked. */
    public ?DateTimeImmutable $consentedAt;

    /**
     * @param  string|null  $locale  nl or en, the language of the confirmation mail
     * @param  string|null  $source  a short origin such as booking-form, at most 64 characters
     * @param  string|null  $reference  your own reference such as a booking number, at most 100 characters
     * @param  string|null  $consentText  the sentence next to the box, at most 1000 characters
     * @param  Confirmation|null  $confirmation  null leaves it to ListMailing, which means Confirmation::Email
     */
    public function __construct(
        public string $email,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $locale = null,
        public ?string $source = null,
        public ?string $reference = null,
        ?DateTimeInterface $consentedAt = null,
        public ?string $consentText = null,
        public ?Confirmation $confirmation = null,
    ) {
        $this->consentedAt = $consentedAt === null ? null : DateTimeImmutable::createFromInterface($consentedAt);
    }

    /**
     * The same sign-up with another way of confirming it.
     */
    public function withConfirmation(Confirmation $confirmation): self
    {
        return new self(
            email: $this->email,
            firstName: $this->firstName,
            lastName: $this->lastName,
            locale: $this->locale,
            source: $this->source,
            reference: $this->reference,
            consentedAt: $this->consentedAt,
            consentText: $this->consentText,
            confirmation: $confirmation,
        );
    }

    /**
     * The request body. Fields without a value are left out.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'locale' => $this->locale,
            'source' => $this->source,
            'reference' => $this->reference,
            'consented_at' => $this->consentedAt?->format(DateTimeInterface::ATOM),
            'consent_text' => $this->consentText,
            'confirmation' => $this->confirmation?->value,
        ], fn (?string $value): bool => $value !== null);
    }
}
