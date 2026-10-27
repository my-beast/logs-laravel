<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Transport;

use Closure;
use Illuminate\Support\Facades\Log;
use MyBeast\Logs\Laravel\Spool\Spool;
use Throwable;

/**
 * Sends the spool to the service, oldest first, a batch at a time; a batch leaves the spool only once the service
 * answered yes. The scheduler runs it every few seconds (mybeast:logs-ship); right after a response or a job the
 * process that wrote lines runs a short round too - at most every 2 seconds per server, and not while the service is
 * failing (then the scheduler alone keeps trying, so no request ever waits on a service that is away).
 *
 * What the service will never take is not retried for ever: lines it refuses one by one are dropped with a warning;
 * a body it refuses whole (422) is set aside in failed/. A batch that is too big (413) goes again in halves. Lines
 * refused because the project's month is used up are dropped too, and said once a month (monthLimit()).
 */
final readonly class Shipper
{
    private const SOON_EVERY_SECONDS = 2;

    private const MAX_BATCH_BYTES = 4 * 1024 * 1024;

    /** @param  Closure(string, string): string  $notice  makes the spool's own line ("the spool was full …") */
    public function __construct(
        private Spool $spool,
        private ?ServiceClient $client,
        private int $batchSize,
        private Closure $notice,
    ) {}

    public function on(): bool
    {
        return $this->client !== null;
    }

    /** After a response or a job, in the process that wrote lines: a short round, when one is due. */
    public function shipSoon(): void
    {
        if ($this->client === null || $this->spool->appended() === 0) {
            return;
        }

        $state = $this->spool->state();

        if (isset($state['failing_since']) || (int) ($state['last_try'] ?? 0) > time() - self::SOON_EVERY_SECONDS) {
            return;
        }

        $this->ship(timeout: 2, connectTimeout: 1, maxBatches: 2);
    }

    public function ship(int $timeout = 10, int $connectTimeout = 3, int $maxBatches = 100): ShipResult
    {
        if ($this->client === null) {
            return new ShipResult(waitingBytes: $this->spool->waitingBytes());
        }

        $client = $this->client;

        return $this->spool->exclusively(function () use ($client, $timeout, $connectTimeout, $maxBatches): ShipResult {
            $state = $this->spool->state();
            $state['last_try'] = time();
            $this->spool->saveState($state);
            $this->spool->forgetAppended();

            $sent = 0;
            $refused = 0;
            $size = $this->batchSize;
            $error = null;

            for ($batches = 0; $batches < $maxBatches; $batches++) {
                $this->spool->rotate();
                $file = $this->spool->readyFiles()[0] ?? null;

                if ($file === null) {
                    break;
                }

                $read = $this->spool->read($file, $size, self::MAX_BATCH_BYTES);

                if ($read['lines'] === []) {
                    $this->spool->done($file);

                    continue;
                }

                try {
                    $answer = $client->send($read['lines'], $timeout, $connectTimeout);
                } catch (TransportFailed $e) {
                    if ($e->status === 413 && count($read['lines']) > 1) {
                        $size = max(1, intdiv(count($read['lines']), 2));

                        continue;
                    }

                    if (in_array($e->status, [400, 422], true)) {
                        $this->spool->quarantine($read['lines']);
                        $this->spool->advance($file, $read['offset']);
                        Log::warning('MyBeast logs: the service refused a batch whole; it is kept in '.$this->spool->directory().'/failed', ['reason' => $e->getMessage()]);

                        continue;
                    }

                    $error = $e->getMessage();
                    break;
                }

                foreach ($this->monthLimit($answer) as $line) {
                    Log::warning('MyBeast logs: the service refused a line', $line);
                }

                $sent += $answer['accepted'] + $answer['duplicates'];
                $refused += count($answer['refused']);
                $read['end'] ? $this->spool->done($file) : $this->spool->advance($file, $read['offset']);
            }

            $this->remember($error, $sent);
            $this->cap();

            return new ShipResult($sent, $refused, $this->spool->waitingBytes(), $error);
        }) ?? ShipResult::busy();
    }

    /** Since when it fails and why - said once when it starts and once when it ends, not on every round. */
    private function remember(?string $error, int $sent): void
    {
        $state = $this->spool->state();

        if ($error !== null && ! isset($state['failing_since'])) {
            Log::warning('MyBeast logs: lines wait in the spool, the service did not take them', ['reason' => $error]);
            $state['failing_since'] = time();
        }

        if ($error === null && isset($state['failing_since'])) {
            Log::info('MyBeast logs: the service takes lines again', ['waited_seconds' => time() - (int) $state['failing_since'], 'sent' => $sent]);
            unset($state['failing_since']);
        }

        $state['last_error'] = $error;
        $this->spool->saveState($state);
    }

    /**
     * Past the project's monthly limit (its package's) the service refuses the rest of the month's lines as
     * `monthly-limit`. They are dropped like any refused line - sent again they would be refused again until the 1st,
     * UTC - but said once a month, and once when the service takes lines again, not once each: that would be a
     * warning for every line the app writes.
     *
     * @param  array{accepted: int, duplicates: int, refused: list<array<string, mixed>>}  $answer
     * @return list<array<string, mixed>> the lines refused for a reason of their own
     */
    private function monthLimit(array $answer): array
    {
        $others = array_values(array_filter($answer['refused'], fn (array $line): bool => ($line['reason'] ?? null) !== 'monthly-limit'));
        $over = count($answer['refused']) - count($others);
        $state = $this->spool->state();
        $noted = $state['monthly_limit'] ?? null;

        if ($over > 0 && $noted !== gmdate('Y-m')) {
            $state['monthly_limit'] = gmdate('Y-m');
            $this->spool->saveState($state);
            Log::warning("MyBeast logs: the project has used up this month's lines; the service drops the rest until the 1st (UTC).", ['dropped' => $over]);
        } elseif ($over === 0 && $answer['accepted'] + $answer['duplicates'] > 0 && $noted !== null) {
            unset($state['monthly_limit']);
            $this->spool->saveState($state);
            Log::info('MyBeast logs: the service takes lines again.');
        }

        return $others;
    }

    /** Over the cap new lines are not kept; back under it, a line says from when to when. */
    private function cap(): void
    {
        $since = $this->spool->checkCap();

        if ($since === null) {
            return;
        }

        try {
            $this->spool->append(($this->notice)(
                'warning',
                'MyBeast logs: the spool was full from '.date('Y-m-d H:i:s', $since).' to '.date('Y-m-d H:i:s').' (its cap is reached while the service is away): lines written then were not kept.',
            ));
        } catch (Throwable) {
            // Saying so must not break a round.
        }
    }
}
