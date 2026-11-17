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
     * was sent and nothing changed. Only a confirmation link the customer
     * clicks lifts that, see Confirmation::Link.
     */
    case Suppressed = 'suppressed';

    /** Whether the customer still has to confirm before they are on the list. */
    public function awaitsConfirmation(): bool
    {
        return $this === self::Pending || $this === self::ResubscribePending;
    }
}
