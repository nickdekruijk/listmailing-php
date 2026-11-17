<?php

namespace NickDeKruijk\ListMailing\Laravel;

use Closure;
use InvalidArgumentException;
use NickDeKruijk\ListMailing\ClientInterface;
use NickDeKruijk\ListMailing\Confirmation;
use NickDeKruijk\ListMailing\MembershipStatus;
use NickDeKruijk\ListMailing\StatusResult;
use NickDeKruijk\ListMailing\SubscribeOutcome;
use NickDeKruijk\ListMailing\SubscribeResult;
use NickDeKruijk\ListMailing\Subscription;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Stands in for the client in the tests of your site, see ListMailing::fake().
 * Every call is recorded. A sign-up answers Pending unless told otherwise.
 */
class ClientFake implements ClientInterface
{
    /** @var list<array{subscription: Subscription, listId: string, idempotencyKey: string|null}> */
    private array $subscribed = [];

    /** @var list<array{email: string, listId: string}> */
    private array $looked = [];

    /** @var list<SubscribeOutcome|SubscribeResult|Throwable> */
    private array $subscribeAnswers = [];

    /** @var list<MembershipStatus|StatusResult|Throwable> */
    private array $statusAnswers = [];

    public function __construct(private readonly ?string $defaultList = null) {}

    /**
     * The answers to the next sign-ups, in order. An outcome becomes a result,
     * with a fake confirmation URL when the sign-up asked for a link. A
     * Throwable is thrown. Once they are used up, sign-ups answer Pending.
     */
    public function respondWith(SubscribeOutcome|SubscribeResult|Throwable ...$answers): static
    {
        array_push($this->subscribeAnswers, ...$answers);

        return $this;
    }

    /**
     * The answers to the next status lookups, in order. Once they are used up,
     * lookups answer NotSubscribed.
     */
    public function statusWith(MembershipStatus|StatusResult|Throwable ...$answers): static
    {
        array_push($this->statusAnswers, ...$answers);

        return $this;
    }

    /**
     * Like Client::withTimeout(), but returns the fake itself, so the calls
     * made on it show up in the asserts.
     */
    public function withTimeout(float $seconds): static
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('The ListMailing timeout must be more than 0 seconds.');
        }

        return $this;
    }

    public function subscribe(Subscription $subscription, ?string $listId = null, ?string $idempotencyKey = null): SubscribeResult
    {
        $listId = $this->list($listId);
        $this->subscribed[] = ['subscription' => $subscription, 'listId' => $listId, 'idempotencyKey' => $idempotencyKey];

        $answer = array_shift($this->subscribeAnswers) ?? SubscribeOutcome::Pending;

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        if ($answer instanceof SubscribeResult) {
            return $answer;
        }

        $link = $subscription->confirmation === Confirmation::Link && $answer->awaitsConfirmation();

        return new SubscribeResult(
            outcome: $answer,
            subscriberId: in_array($answer, [SubscribeOutcome::Subscribed, SubscribeOutcome::AlreadySubscribed], true) ? 'fake-subscriber-id' : null,
            confirmationUrl: $link ? 'https://listmailing.test/subscribe/confirm/fake' : null,
            created: $answer === SubscribeOutcome::Pending || $answer === SubscribeOutcome::Subscribed,
        );
    }

    public function status(string $email, ?string $listId = null): StatusResult
    {
        $this->looked[] = ['email' => $email, 'listId' => $this->list($listId)];

        $answer = array_shift($this->statusAnswers) ?? MembershipStatus::NotSubscribed;

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer instanceof StatusResult ? $answer : new StatusResult($answer);
    }

    /**
     * Assert a sign-up was asked for. Pass an address, or a closure that gets
     * the Subscription, the list id and the Idempotency-Key and returns true
     * for a match.
     *
     * @param  string|Closure(Subscription, string, string|null): bool  $email
     */
    public function assertSubscribed(string|Closure $email, ?string $listId = null): void
    {
        Assert::assertNotEmpty(
            $this->matching($email, $listId),
            'No sign-up was asked for '.$this->describe($email, $listId).'.',
        );
    }

    /**
     * Assert no sign-up was asked for this address or closure.
     *
     * @param  string|Closure(Subscription, string, string|null): bool  $email
     */
    public function assertNotSubscribed(string|Closure $email, ?string $listId = null): void
    {
        Assert::assertEmpty(
            $this->matching($email, $listId),
            'A sign-up was asked for '.$this->describe($email, $listId).'.',
        );
    }

    public function assertNothingSubscribed(): void
    {
        Assert::assertEmpty($this->subscribed, count($this->subscribed).' sign-up(s) were asked for, none were expected.');
    }

    public function assertSubscribedTimes(int $times): void
    {
        Assert::assertCount($times, $this->subscribed, "Expected {$times} sign-up(s), got ".count($this->subscribed).'.');
    }

    /**
     * Assert the status of this address was looked up.
     */
    public function assertStatusChecked(string $email, ?string $listId = null): void
    {
        $found = array_filter($this->looked, fn (array $call): bool => strcasecmp($call['email'], $email) === 0
            && ($listId === null || $call['listId'] === $listId));

        Assert::assertNotEmpty($found, "The status of {$email} was not looked up.");
    }

    /**
     * Every recorded sign-up, for checks of your own.
     *
     * @return list<array{subscription: Subscription, listId: string, idempotencyKey: string|null}>
     */
    public function subscriptions(): array
    {
        return $this->subscribed;
    }

    /**
     * @param  string|Closure(Subscription, string, string|null): bool  $email
     * @return list<array{subscription: Subscription, listId: string, idempotencyKey: string|null}>
     */
    private function matching(string|Closure $email, ?string $listId): array
    {
        return array_values(array_filter($this->subscribed, function (array $call) use ($email, $listId): bool {
            if ($listId !== null && $call['listId'] !== $listId) {
                return false;
            }

            return $email instanceof Closure
                ? $email($call['subscription'], $call['listId'], $call['idempotencyKey']) === true
                : strcasecmp($call['subscription']->email, $email) === 0;
        }));
    }

    private function describe(string|Closure $email, ?string $listId): string
    {
        $who = $email instanceof Closure ? 'the given callback' : $email;

        return $listId === null ? $who : "{$who} on list {$listId}";
    }

    private function list(?string $listId): string
    {
        $listId ??= $this->defaultList;

        if ($listId === null || $listId === '') {
            throw new InvalidArgumentException('No list id given and no default list set.');
        }

        return $listId;
    }
}
