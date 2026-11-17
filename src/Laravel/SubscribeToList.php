<?php

namespace NickDeKruijk\ListMailing\Laravel;

use DateInterval;
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
use RuntimeException;
use Throwable;

/**
 * Signs an address up in the background, so an error from the API never
 * stops a booking. A timeout, an error on ListMailing's side or a limit is
 * tried again later, honouring Retry-After, with the same Idempotency-Key:
 * a retry never mails twice. Any other error is reported and fails the job.
 *
 * Dispatched after the database transaction commits, so a booking that is
 * rolled back signs no one up. Fires SignUpProcessed after an answer and
 * SignUpFailed when it gives up.
 */
class SubscribeToList implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Seconds to wait before the next try when the answer gives no Retry-After. */
    public const BACKOFF = [60, 300, 900, 1800, 3600];

    /**
     * How long the job keeps trying, counted from the moment it becomes
     * available, so after any delay. Long enough to get past a full day,
     * which lasts until midnight UTC.
     */
    public const RETRY_HOURS = 48;

    /**
     * The longest a try is put back for. A full day can be 24 hours away, and
     * a queue such as SQS refuses a delay of more than 12; the job then just
     * asks again and waits once more.
     */
    public const MAX_RELEASE = 43200;

    public readonly ?string $listId;

    public readonly string $idempotencyKey;

    /** When the job was dispatched, as a Unix timestamp. */
    public readonly int $createdAt;

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
        // Laravel's clock, the one the worker compares retryUntil() with, so
        // a test that freezes time sees one moment and not two.
        $this->createdAt = now()->getTimestamp();

        $queue = config('listmailing.queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    /**
     * The key sent with every try. With a reference it is the same for the
     * same list, address, confirmation and reference, so dispatching the same
     * booking twice signs up once. ListMailing keeps keys per token and per
     * list; the list and the address are part of the key all the same, so
     * one key always means one sign-up. Without a reference every dispatch
     * gets a key of its own.
     *
     * @param  string|null  $listId  null uses config('listmailing.list'), as dispatch() does, so you get the key the job would send
     */
    public static function idempotencyKey(Subscription $subscription, ?string $listId = null): string
    {
        $listId ??= config('listmailing.list');
        $listId = is_string($listId) ? $listId : '';

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

    /**
     * The client is resolved here and not injected, so a missing token fails
     * the job like any other error instead of throwing before handle() runs.
     */
    public function handle(): void
    {
        if ($this->listId === null) {
            $this->failWith(new InvalidArgumentException('No list id given to SubscribeToList and listmailing.list is not set.'));

            return;
        }

        try {
            $result = app(ClientInterface::class)->subscribe($this->subscription, $this->listId, $this->idempotencyKey);
        } catch (Retryable $e) {
            $wait = max(1, $e->retryAfter() ?? $this->backoffDelay());

            // Out of time: fail with the real reason instead of letting the
            // worker fail it later without one. Judged on the whole wait that
            // was asked for, not on the part of it one release may cover.
            if (now()->getTimestamp() + $wait > $this->retryUntil()->getTimestamp()) {
                $this->failWith($e);

                return;
            }

            $this->release(min(self::MAX_RELEASE, $wait));

            return;
        } catch (ListMailingException|InvalidArgumentException $e) {
            $this->failWith($e);

            return;
        }

        // The sign-up went through, whatever a listener makes of it. An
        // exception let out of here would make the worker try the job again,
        // at once and for two days: every try replays the request, fires this
        // event again and spends the rate limit of the token, and once
        // ListMailing has forgotten the key it is a new sign-up with a new
        // mail. So it is reported, and that is all.
        try {
            event(new SignUpProcessed($this->subscription, $this->listId, $result));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * How long the worker waits when the job is put back by something other
     * than handle() itself, a worker that was killed for instance. Without
     * this a worker started with the default --backoff=0 would try again at
     * once.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return self::BACKOFF;
    }

    /**
     * Laravel reads this when the job is pushed, which is after a delay() has
     * been set, and handle() reads it again from the same serialized values.
     */
    public function retryUntil(): DateTimeInterface
    {
        return new DateTimeImmutable('@'.($this->availableAt() + self::RETRY_HOURS * 3600));
    }

    /**
     * When the job becomes available: the moment it was dispatched plus its
     * delay, as a Unix timestamp.
     */
    public function availableAt(): int
    {
        $delay = is_array($this->delay) ? max([0, ...$this->delay]) : $this->delay;

        return match (true) {
            $delay instanceof DateTimeInterface => max($this->createdAt, $delay->getTimestamp()),
            $delay instanceof DateInterval => (new DateTimeImmutable('@'.$this->createdAt))->add($delay)->getTimestamp(),
            is_numeric($delay) => $this->createdAt + max(0, (int) $delay),
            default => $this->createdAt,
        };
    }

    private function backoffDelay(): int
    {
        $index = min(max(0, $this->attempts() - 1), count(self::BACKOFF) - 1);

        return self::BACKOFF[$index];
    }

    /**
     * Called by Laravel whenever the job fails: through failWith(), but also
     * when the worker gives up on it, after a timeout or once retryUntil()
     * has passed while the job waited in the queue. So SignUpFailed is fired
     * here, once, and not in failWith().
     */
    public function failed(?Throwable $e = null): void
    {
        event(new SignUpFailed($this->subscription, $this->listId, $e ?? new RuntimeException('SubscribeToList failed without a reason.')));
    }

    /**
     * Report the error, so it reaches the error tracker, and fail without a
     * retry, which calls failed(). Nothing is thrown, so a sync queue does not
     * break the request.
     */
    private function failWith(Throwable $e): void
    {
        report($e);

        $this->fail($e);
    }
}
