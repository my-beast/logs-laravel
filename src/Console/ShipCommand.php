<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Console;

use Illuminate\Console\Command;
use MyBeast\Logs\Laravel\Logs;

/** Every few seconds (registered on the scheduler): what waits in the spool goes to the service. */
final class ShipCommand extends Command
{
    protected $signature = 'mybeast:logs-ship';

    protected $description = 'Send the log lines that wait in the spool to services.my-beast.com/logs';

    public function handle(Logs $logs): int
    {
        if (! $logs->enabled()) {
            $this->line('My Beast logs is off (no URL or server secret): nothing to send.');

            return self::SUCCESS;
        }

        $result = $logs->ship();

        if ($result->busy) {
            $this->line('Another process is sending right now.');

            return self::SUCCESS;
        }

        $this->info("Sent {$result->sent} line(s).".($result->refused > 0 ? " The service refused {$result->refused}." : ''));

        if ($result->error !== null) {
            $this->warn('The rest waits ('.self::size($result->waitingBytes).'): '.$result->error);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    public static function size(int $bytes): string
    {
        return $bytes < 1024 * 1024 ? round($bytes / 1024, 1).' KB' : round($bytes / 1024 / 1024, 1).' MB';
    }
}
