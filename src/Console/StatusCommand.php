<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Console;

use Illuminate\Console\Command;
use MyBeast\Logs\Laravel\Logs;

/** Is it on, what waits, since when does the service not take lines and why. */
final class StatusCommand extends Command
{
    protected $signature = 'mybeast:logs-status';

    protected $description = 'Show whether My Beast logs is on and what waits in its spool';

    public function handle(Logs $logs): int
    {
        $spool = $logs->spool();
        $state = $spool->state();

        $this->table(['My Beast logs', ''], [
            ['On', $logs->enabled() ? 'yes, to '.config('mybeast-logs.url') : 'no (no URL or server secret, or switched off)'],
            ['Environment', (string) config('mybeast-logs.environment')],
            ['Spool', $spool->directory()],
            ['Waiting', ShipCommand::size($spool->waitingBytes()).' in '.count($spool->readyFiles()).' file(s) and the journal'],
            ['Full', $spool->fullSince() === null ? 'no' : 'since '.date('Y-m-d H:i:s', (int) $spool->fullSince()).': new lines are not kept'],
            ['Last try', isset($state['last_try']) ? date('Y-m-d H:i:s', (int) $state['last_try']) : 'never'],
            ['Failing', isset($state['failing_since']) ? 'since '.date('Y-m-d H:i:s', (int) $state['failing_since']).': '.mb_strimwidth((string) $state['last_error'], 0, 110, '…') : 'no'],
        ]);

        return self::SUCCESS;
    }
}
