<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Channel;

use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use MyBeast\Logs\Laravel\Spool\Spool;
use Throwable;

/**
 * The `mybeast` channel's handler: a line goes into the spool - a file append, never a network call - and the
 * record bubbles on to the stack's other channels. Nothing here may throw into the app: a line that cannot be kept is
 * counted, not raised. The SDK's own lines ("My Beast logs: …") go to the host's other channels only.
 */
final class SpoolHandler extends AbstractHandler
{
    private int $dropped = 0;

    public function __construct(
        private readonly LineBuilder $lines,
        private readonly Spool $spool,
        int|string|Level $level = Level::Debug,
    ) {
        parent::__construct($level, true);
    }

    public function handle(LogRecord $record): bool
    {
        if (! $this->isHandling($record) || str_starts_with($record->message, 'My Beast logs:')) {
            return false;
        }

        try {
            if (! $this->spool->append(LineBuilder::encode($this->lines->build($record)))) {
                $this->dropped++;
            }
        } catch (Throwable) {
            $this->dropped++;
        }

        return false;
    }

    /** Lines this process could not keep (the spool was full or not writable). */
    public function dropped(): int
    {
        return $this->dropped;
    }
}
