<?php

namespace NickDeKruijk\ListMailing;

/**
 * Where an address stands on a list.
 */
enum MembershipStatus: string
{
    /** On the list. */
    case Subscribed = 'subscribed';

    /** A confirmation was asked for in the last 24 hours. */
    case Pending = 'pending';

    /** Was on the list and is not any more. */
    case Unsubscribed = 'unsubscribed';

    /** Blocked after a spam complaint or a hard bounce. Gets no mail. A link the customer clicks lifts a bounce, nothing lifts a complaint. */
    case Suppressed = 'suppressed';

    /** Unknown on this list. */
    case NotSubscribed = 'not_subscribed';
}
