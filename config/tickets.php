<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Completion confirmation
    |--------------------------------------------------------------------------
    |
    | A completed ticket waits for the requester to confirm it. Tickets nobody
    | answers are closed automatically after this many calendar days.
    |
    */

    'autoclose_days' => (int) env('TICKET_AUTOCLOSE_DAYS', 30),

    /*
    | Base URL of the requestor portal. The emailed link is
    | {requestor_portal_url}/confirm/{token}; it comes from here, never from
    | the request, so a forged Host header cannot change where it points.
    */

    'requestor_portal_url' => env('REQUESTOR_PORTAL_URL', 'https://set-requestor-portal-url.invalid'),

    /*
    | Confirmation emails: failed sends are retried by the scheduler, waiting
    | these many minutes after each failed attempt, up to the maximum.
    */

    'mail_max_attempts' => 5,

    'mail_retry_minutes' => [5, 15, 30, 60],

];
