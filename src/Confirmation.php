<?php

namespace NickDeKruijk\ListMailing;

/**
 * How a sign-up is confirmed.
 */
enum Confirmation: string
{
    /** ListMailing sends a confirmation mail from your sender. The default. */
    case Email = 'email';

    /** Nothing is sent: you get the confirmation link back to put in a mail of your own. */
    case Link = 'link';

    /** Single opt-in, only after ListMailing has enabled it for your account. */
    case None = 'none';
}
