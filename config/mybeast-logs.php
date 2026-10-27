<?php

declare(strict_types=1);

/*
 * MyBeast logs: this app's log lines, sent to logs.my-beast.com. Put `mybeast` in the log stack
 * (LOG_STACK=single,mybeast) - the SDK adds the channel itself unless config/logging.php names one already. The URL
 * and the server secret come from the project's page there (Overview → Connect a host; Settings → Server secrets).
 *
 * Lines are written to a spool on this server first (a file append, never a network call), and sent from there after
 * the response has gone out and every few seconds by the scheduler. While the service is away they wait.
 * Missing or malformed values switch the SDK off - it never throws at boot and never breaks a page.
 */
return [
    // false switches everything off (a host's own test suite, a checkout without the service).
    'enabled' => (bool) env('MYBEAST_LOGS_ENABLED', true),

    // The service, e.g. https://logs.my-beast.com (no trailing slash needed).
    'url' => env('MYBEAST_LOGS_URL'),

    // sk_….…: this server's secret for sending its lines.
    'secret' => env('MYBEAST_LOGS_SECRET'),

    // Sent with every line: production, staging, local, … The service keeps each environment's lines on its own.
    'environment' => env('MYBEAST_LOGS_ENVIRONMENT', env('APP_ENV', 'production')),

    // The release (build) this process runs; unset = the git commit, read from .git (no shell).
    'release' => env('MYBEAST_LOGS_RELEASE', env('APP_RELEASE')),

    // This machine's name; unset = gethostname().
    'host' => env('MYBEAST_LOGS_HOST'),

    // The mildest level the `mybeast` channel takes (debug, info, notice, warning, error, …).
    'level' => env('MYBEAST_LOGS_LEVEL', 'debug'),

    // Where lines wait to be sent (files - never the database, which may be what fails), and how big the spool may
    // grow while the service is away. Past that, new lines are not kept, and a line says so when there is room again.
    'spool_path' => env('MYBEAST_LOGS_SPOOL_PATH', storage_path('app/private/mybeast-logs')),
    'spool_max_mb' => (int) env('MYBEAST_LOGS_SPOOL_MAX_MB', 100),

    // Lines per upload (the service takes up to 1000).
    'batch_size' => 500,

    // The scheduler sends the spool this often: 1, 2, 5, 10, 15, 20, 30 or 60 seconds (needs `schedule:run` from cron,
    // or `schedule:work`). Right after a response or a job the request's own process sends too, at most every 2 seconds
    // and never while the service is failing - set false to leave every send to the scheduler.
    'ship_every_seconds' => (int) env('MYBEAST_LOGS_SHIP_EVERY', 10),
    'ship_after_response' => (bool) env('MYBEAST_LOGS_SHIP_AFTER_RESPONSE', true),

    // A request's id: kept from this header when a proxy sets one (8 to 64 of A-Z a-z 0-9 . _ : -), else made here,
    // and put on the response under the same name. Every line of the request carries it, and so do the jobs it queues.
    'request_id_header' => env('MYBEAST_LOGS_REQUEST_ID_HEADER', 'X-Request-Id'),

    // The host's own patterns, on top of e-mails, IBANs, Finnish personal ids, phone numbers and tokens:
    // ['/\bCUST-\d{6}\b/' => '[customer]']. For rules a pattern cannot say: Logs::scrubUsing(fn (string $t) => …).
    'scrub' => [],
];
