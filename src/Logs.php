<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel;

use Closure;
use MyBeast\Logs\Laravel\Scope\Scope;
use MyBeast\Logs\Laravel\Scrubbing\Scrubber;
use MyBeast\Logs\Laravel\Spool\Spool;
use MyBeast\Logs\Laravel\Transport\Shipper;
use MyBeast\Logs\Laravel\Transport\ShipResult;

/** The SDK's face (Facades\Logs). The log channel itself needs none of this: `Log::info(…)` is all a host writes. */
final readonly class Logs
{
    public function __construct(
        private Scope $scope,
        private Spool $spool,
        private Shipper $shipper,
    ) {}

    /** On: a URL and a server secret, and not switched off. */
    public function enabled(): bool
    {
        return $this->shipper->on();
    }

    /** The id every line of this request (or job, or command run) carries - for other SDKs to put on what they send. */
    public function requestId(): ?string
    {
        return $this->scope->requestId();
    }

    /** A rule of the host's own, run on every text after the built-in patterns. @param  Closure(string): string  $rule */
    public function scrubUsing(Closure $rule): void
    {
        Scrubber::extend($rule);
    }

    /** Sends what waits now (the scheduler does this every few seconds). */
    public function ship(): ShipResult
    {
        return $this->shipper->ship();
    }

    public function spool(): Spool
    {
        return $this->spool;
    }
}
