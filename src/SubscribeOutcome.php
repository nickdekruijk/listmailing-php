<?php

namespace NickDeKruijk\ListMailing;

/**
 * What a sign-up did.
 */
enum SubscribeOutcome: string
{
    /** A confirmation was asked for. A mail went out, or was held back because one was sent recently. */
    case Pending = 'pending';

    /** Already on the list. Nothing was sent. */
    case AlreadySubscribed = 'already_subscribed';

    /** Unsubscribed before and signing up again. A confirmation was asked for. */
    case ResubscribePending = 'resubscribe_pending';

    /** On the list straight away (single opt-in). */
    case Subscribed = 'subscribed';

    /**
     * The address is blocked after a spam complaint or a hard bounce. Nothing
     * was sent and nothing changed. After a hard bounce a confirmation link
     * the customer clicks lifts that, see Confirmation::Link. After a spam
     * complaint nothing does: asking for a link gives Suppressed again.
     */
    case Suppressed = 'suppressed';

    /**
     * ListMailing answered that the sign-up went through, with a status this
     * version of the package does not know. Never sent by ListMailing as
     * such: SubscribeResult::$status holds the word it did send. Update the
     * package to get its meaning.
     */
    case Unknown = 'unknown';

    /** Whether the customer still has to confirm before they are on the list. */
    public function awaitsConfirmation(): bool
    {
        return $this === self::Pending || $this === self::ResubscribePending;
    }

    /** Whether the address is on the list now. */
    public function isOnList(): bool
    {
        return $this === self::Subscribed || $this === self::AlreadySubscribed;
    }
}
