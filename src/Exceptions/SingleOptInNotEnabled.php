<?php

namespace NickDeKruijk\ListMailing\Exceptions;

/**
 * HTTP 403: Confirmation::None without ListMailing's approval.
 */
class SingleOptInNotEnabled extends ListMailingException {}
