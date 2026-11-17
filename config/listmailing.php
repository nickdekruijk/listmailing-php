<?php

return [

    /*
     * The API token from the API screen in ListMailing. It starts with lm_.
     * Keep it in .env, never in code or in anything a visitor can see.
     */
    'token' => env('LISTMAILING_TOKEN'),

    /*
     * The base URL from the API screen. Only https is accepted.
     */
    'base_url' => env('LISTMAILING_BASE_URL', 'https://listmailing.nl/api/v1'),

    /*
     * The list id used when a call or a job names none.
     */
    'list' => env('LISTMAILING_LIST'),

    /*
     * Seconds to wait for an answer. A job that times out tries again later.
     * Empty, 0 or anything that is not a number uses the default of 10.
     */
    'timeout' => (float) env('LISTMAILING_TIMEOUT', 10),

    /*
     * nl or en: the language of the confirmation mail when a sign-up has
     * none ListMailing knows, such as a visitor on the German version of
     * your site. Null leaves it to the language of your page in ListMailing.
     */
    'locale' => env('LISTMAILING_LOCALE'),

    /*
     * The queue for SubscribeToList. Null uses the default queue.
     */
    'queue' => env('LISTMAILING_QUEUE'),

];
