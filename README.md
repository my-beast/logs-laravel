# mybeast/logs-laravel

The Laravel SDK for [My Beast logs](https://services.my-beast.com/logs): a log channel that sends your app's lines to the
service - scrubbed before they leave, without slowing a request, and without losing any while the service is away.
PHP ≥ 8.2, Laravel 12 and 13.

## Plug it in

```bash
composer require mybeast/logs-laravel
```

```dotenv
LOG_STACK=single,mybeast
MYBEAST_LOGS_URL=https://services.my-beast.com/logs
MYBEAST_LOGS_SECRET=sk_….…          # the project's Settings → Server secrets (shown once)
MYBEAST_LOGS_ENVIRONMENT=production # defaults to APP_ENV
```

That is all: the SDK adds the `mybeast` channel (unless `config/logging.php` names one), and the scheduler sends
what waits every 10 seconds (`schedule:run` from cron, or `schedule:work`). In your test suite, set
`MYBEAST_LOGS_ENABLED=false` (phpunit.xml) - off, the channel takes lines and keeps none.

A channel of its own name: `'payments' => ['driver' => 'mybeast', 'name' => 'payments']`, then
`Log::channel('payments')->error(…)` arrives as channel `payments`. A channel's `level` is its mildest level
(`MYBEAST_LOGS_LEVEL` for the default one).

## How a line travels

1. **Written to a spool on this server** (`storage/app/private/mybeast-logs`): one append to a journal file - no
   network, no database. A request waits for nothing else.
2. **Sent from there:** right after the response has gone out (PHP-FPM has answered), at most every 2 seconds per
   server and never while the service is failing; after each queue job; and every `ship_every_seconds` by the
   scheduler (`mybeast:logs-ship`). Batches of 500, gzip, oldest first. A batch leaves the spool only once the
   service said it has it.
3. **While the service is away** the lines wait, in order, and go when it is back - none lost, none twice (each line
   carries an id the service stores once). The spool may grow to `spool_max_mb` (100 MB); past that new lines are not
   kept until there is room, and a line then says from when to when. `mybeast:logs-status` shows what waits.
4. **Past the project's monthly limit** (its package's) the service refuses the rest of the month's lines as
   `monthly-limit`. They are dropped, and the log says so once a month, and once when the service takes lines again.

## What is taken out

Before anything leaves: e-mail addresses, IBANs, Finnish personal identity codes, phone numbers, bearer tokens and long
secrets, in the message and in every string of the context; a value under a secret-looking key (`password`, `token`,
`secret`, `api_key`, `authorization`, `cookie`, `card_number`, …) goes whole; a model in the context is its class and
key only, never its attributes; an exception is its class, message, place and trace. The service scrubs again on
arrival. Your own rules:

```php
// config/mybeast-logs.php (php artisan vendor:publish --tag=mybeast-logs-config)
'scrub' => ['/\bCUST-\d{6}\b/' => '[customer]'],

// or in a service provider, for what a pattern cannot say
Logs::scrubUsing(fn (string $text): string => PersonalIdentityCode::maskIn($text));
```

## What each line carries

The environment, release (`MYBEAST_LOGS_RELEASE`, else the git commit), host, channel, and the **request id**: the
same on every line of one HTTP request (an incoming `X-Request-Id` is kept when it fits, else one is made, and it is put
on the response), on the jobs it queues (through Laravel's hidden Context), and on each artisan command run. Also what
was running, without values: `GET /invoices/{invoice}/send`, `job App\Jobs\SendInvoice`, `artisan invoices:remind`.
`Logs::requestId()` hands the id to whatever else wants it (the errors SDK will).

## The demo host

`workbench/` is a tiny Laravel app with this SDK (Orchestra Testbench): a checkout that logs - personal data
included - and queues a job.

```bash
cp workbench/.env.example workbench/.env     # put a project's server secret in MYBEAST_LOGS_SECRET
composer demo                                 # 40 checkouts through the app's HTTP kernel, timed
vendor/bin/testbench mybeast:logs-status      # what waits in the demo's spool
vendor/bin/testbench mybeast:logs-ship        # send it now
```

## Develop

```bash
composer install
vendor/bin/phpunit
vendor/bin/pint
```

`schemas/` is a copy of the Logs module's contract in services (`../../services/schemas/logs`): a test fails when they
differ.

## Releasing

Add the version to `CHANGELOG.md`, then tag it: `git tag v0.2.0 && git push --tags`. Packagist reads the tag through
its GitHub hook; there is no version in `composer.json`. The Tests workflow runs on the tag as on every push: PHP 8.2
to 8.4 against Laravel 12 and 13 (Laravel 11 has unfixed advisories, so Composer will not install it).
