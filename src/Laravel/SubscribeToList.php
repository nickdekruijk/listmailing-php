<?php

namespace NickDeKruijk\ListMailing\Laravel;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use NickDeKruijk\ListMailing\ClientInterface;
use NickDeKruijk\ListMailing\Confirmation;
use NickDeKruijk\ListMailing\Exceptions\ListMailingException;
use NickDeKruijk\ListMailing\Exceptions\Retryable;
use NickDeKruijk\ListMailing\Subscription;
use Throwable;

/**
 * Signs an address up in the background, so an error from the API never
 * stops a booking. A timeout, an error on ListMailing's side or a limit is
 * tried again later, honouring Retry-After, with the same Idempotency-Key:
 * a retry never mails twice. Any other error is reported and fails the job.
 *
 * Dispatched after the database transaction commits, so a booking that is
 * rolled back signs no one up.
 */
class SubscribeToList implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Seconds to wait before the next try when the answer gives no Retry-After. */
    public const BACKOFF = [60, 300, 900, 1800, 3600];

    /** How long the job keeps trying. Long enough to get past a full day, which lasts until midnight UTC. */
    public const RETRY_HOURS = 48;

    public readonly ?string $listId;

    public readonly string $idempotencyKey;

    public readonly int $retryUntilTimestamp;

    /**
     * @param  string|null  $listId  null uses config('listmailing.list')
     */
    public function __construct(public readonly Subscription $subscription, ?string $listId = null)
    {
        $listId ??= config('listmailing.list');

        // A missing list is a setup error, but it fails the job, not the
        // request that dispatched it.
        $this->listId = is_string($listId) && $listId !== '' ? $listId : null;
        $this->idempotencyKey = self::idempotencyKey($subscription, (string) $this->listId);
        $this->retryUntilTimestamp = time() + self::RETRY_HOURS * 3600;

        $queue = config('listmailing.queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    /**
     * The key sent with every try. With a reference it is the same for the
     * same list, address, confirmation and reference, so dispatching the same
     * booking twice signs up once. ListMailing keeps keys per token, not per
     * list, which is why the list and the address are part of it. Without a
     * reference every dispatch gets a key of its own.
     */
    public static function idempotencyKey(Subscription $subscription, string $listId): string
    {
        if ($subscription->reference === null || $subscription->reference === '') {
            return bin2hex(random_bytes(16));
        }

        return hash('sha256', implode("\n", [
            $listId,
            mb_strtolower(trim($subscription->email)),
            ($subscription->confirmation ?? Confirmation::Email)->value,
            $subscription->reference,
        ]));
    }

    public function handle(ClientInterface $client): void
    {
        if ($this->listId === null) {
            $this->failWith(new InvalidArgumentException('No list id given to SubscribeToList and listmailing.list is not set.'));

            return;
        }

        try {
            $result = $client->subscribe($this->subscription, $this->listId, $this->idempotencyKey);
        } catch (Retryable $e) {
            $delay = max(1, $e->retryAfter() ?? $this->backoffDelay());

            // Out of time: fail with the real reason instead of letting the
            // worker fail it later without one.
            if (time() + $delay > $this->retryUntilTimestamp) {
                $this->failWith($e);

                return;
            }

            $this->release($delay);

            return;
        } catch (ListMailingException|InvalidArgumentException $e) {
            $this->failWith($e);

            return;
        }

        event(new SignUpProcessed($this->subscription, $this->listId, $result));
    }

    public function retryUntil(): DateTimeInterface
    {
        return new DateTimeImmutable('@'.$this->retryUntilTimestamp);
    }

    private function backoffDelay(): int
    {
        $index = min(max(0, $this->attempts() - 1), count(self::BACKOFF) - 1);

        return self::BACKOFF[$index];
    }

    /**
     * Report the error, so it reaches the error tracker, and fail without a
     * retry. Nothing is thrown, so a sync queue does not break the request.
     */
    private function failWith(Throwable $e): void
    {
        report($e);

        $this->fail($e);
    }
}
