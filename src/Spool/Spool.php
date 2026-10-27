<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Spool;

use Closure;

/**
 * Where lines wait on this server until the service has them - files, never the host's database (it may be what is
 * failing). Every process appends its lines to one journal, a line of JSON each; the shipper moves the journal aside
 * (ready/) and sends it from there, oldest first, remembering how far it got in each file. Nothing is removed before
 * the service said it has it, so a service that is away loses nothing; it only makes the spool grow, up to its cap.
 *
 * Locks: a writer holds rotate.lock shared while it appends (and the journal itself exclusive), the shipper holds it
 * exclusive for the moment it moves the journal - so no line lands in a file that is being sent. ship.lock lets one
 * shipper work at a time; a second one goes away at once.
 */
final class Spool
{
    private const JOURNAL = 'current.ndjson';

    /** @var resource|null kept open for the life of the process: one open less per line */
    private $rotateLock = null;

    private int $appended = 0;

    public function __construct(
        private readonly string $directory,
        private readonly int $maxBytes,
    ) {}

    public function directory(): string
    {
        return $this->directory;
    }

    /** One line (JSON, no newline). False when it was not kept: the spool is full, or the disk said no. */
    public function append(string $json): bool
    {
        if ($this->full() || ! $this->ensure()) {
            return false;
        }

        $lock = $this->rotateLock();

        if ($lock === null || ! flock($lock, LOCK_SH)) {
            return false;
        }

        try {
            $written = @file_put_contents($this->directory.'/'.self::JOURNAL, $json."\n", FILE_APPEND | LOCK_EX);
        } finally {
            flock($lock, LOCK_UN);
        }

        if ($written === false) {
            return false;
        }

        $this->appended++;

        return true;
    }

    /** Lines this process has written since it last sent. */
    public function appended(): int
    {
        return $this->appended;
    }

    public function forgetAppended(): void
    {
        $this->appended = 0;
    }

    /**
     * $work under ship.lock, or null at once when another process holds it.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T|null
     */
    public function exclusively(Closure $work): mixed
    {
        if (! $this->ensure()) {
            return null;
        }

        $lock = @fopen($this->directory.'/ship.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }

            return null;
        }

        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Under ship.lock: the journal moves aside to be sent - only once nothing older waits, so the files stay few. */
    public function rotate(): void
    {
        $journal = $this->directory.'/'.self::JOURNAL;
        clearstatcache(true, $journal);

        if ($this->readyFiles() !== [] || ! is_file($journal) || filesize($journal) === 0) {
            return;
        }

        $lock = $this->rotateLock();

        if ($lock === null || ! @mkdir($this->directory.'/ready', 0750) && ! is_dir($this->directory.'/ready')) {
            return;
        }

        flock($lock, LOCK_EX);

        try {
            @rename($journal, $this->directory.'/ready/'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.ndjson');
        } finally {
            flock($lock, LOCK_UN);
        }
    }

    /** @return list<string> files waiting to be sent, oldest first */
    public function readyFiles(): array
    {
        $files = glob($this->directory.'/ready/*.ndjson') ?: [];
        sort($files);

        return $files;
    }

    /**
     * The next lines of a ready file, from where the last send stopped: at most $max lines and about $maxBytes. A line
     * that is not JSON (cut short by a full disk) is skipped.
     *
     * @return array{lines: list<string>, offset: int, end: bool, skipped: int}
     */
    public function read(string $file, int $max, int $maxBytes): array
    {
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return ['lines' => [], 'offset' => 0, 'end' => true, 'skipped' => 0];
        }

        try {
            fseek($handle, $this->offset($file));
            $lines = [];
            $bytes = 0;
            $skipped = 0;

            while (count($lines) < $max && $bytes < $maxBytes && ($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\n");
                $bytes += strlen($line);

                if ($line === '') {
                    continue;
                }

                if (self::isJson($line)) {
                    $lines[] = $line;
                } else {
                    $skipped++;
                }
            }

            $offset = (int) ftell($handle);

            return ['lines' => $lines, 'offset' => $offset, 'end' => fgets($handle) === false, 'skipped' => $skipped];
        } finally {
            fclose($handle);
        }
    }

    /** The service has everything of $file up to $offset. */
    public function advance(string $file, int $offset): void
    {
        @file_put_contents($file.'.offset', (string) $offset);
    }

    /** The service has all of $file. */
    public function done(string $file): void
    {
        @unlink($file);
        @unlink($file.'.offset');
    }

    /** Lines the service will never take as they are (it refused the whole body): kept aside, never in the way. */
    public function quarantine(array $lines): void
    {
        if (@mkdir($this->directory.'/failed', 0750) || is_dir($this->directory.'/failed')) {
            @file_put_contents($this->directory.'/failed/'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.ndjson', implode("\n", $lines)."\n");
        }
    }

    /** What waits to be sent: the journal and the ready files, less what of them has gone. */
    public function waitingBytes(): int
    {
        $bytes = 0;
        $journal = $this->directory.'/'.self::JOURNAL;
        clearstatcache();

        if (is_file($journal)) {
            $bytes += (int) filesize($journal);
        }

        foreach ($this->readyFiles() as $file) {
            $bytes += max(0, (int) filesize($file) - $this->offset($file));
        }

        return $bytes;
    }

    public function full(): bool
    {
        $flag = $this->directory.'/.full';
        clearstatcache(true, $flag);

        return is_file($flag);
    }

    /** Since when it is full (written by the shipper when it found it over the cap), or null. */
    public function fullSince(): ?int
    {
        $since = $this->full() ? @file_get_contents($this->directory.'/.full') : false;

        return $since === false ? null : (int) $since;
    }

    /**
     * Over the cap: new lines are not kept until there is room. Back under it: the flag goes, and the time it was set
     * comes back (to say in a line what was not kept).
     */
    public function checkCap(): ?int
    {
        $over = $this->waitingBytes() >= $this->maxBytes;
        $since = $this->fullSince();

        if ($over && $since === null) {
            @file_put_contents($this->directory.'/.full', (string) time());
        }

        if (! $over && $since !== null) {
            @unlink($this->directory.'/.full');

            return $since;
        }

        return null;
    }

    /** @return array<string, mixed> the shipper's notes: when it last tried, since when it fails and why */
    public function state(): array
    {
        $state = json_decode((string) @file_get_contents($this->directory.'/state.json'), true);

        return is_array($state) ? $state : [];
    }

    /** @param  array<string, mixed>  $state */
    public function saveState(array $state): void
    {
        if ($this->ensure()) {
            @file_put_contents($this->directory.'/state.json', (string) json_encode($state), LOCK_EX);
        }
    }

    private function offset(string $file): int
    {
        return (int) @file_get_contents($file.'.offset');
    }

    private function ensure(): bool
    {
        return is_dir($this->directory) || @mkdir($this->directory, 0750, true) || is_dir($this->directory);
    }

    /** @return resource|null */
    private function rotateLock()
    {
        if ($this->rotateLock === null) {
            $handle = @fopen($this->directory.'/rotate.lock', 'c');
            $this->rotateLock = $handle === false ? null : $handle;
        }

        return $this->rotateLock;
    }

    private static function isJson(string $line): bool
    {
        if (function_exists('json_validate')) {
            return json_validate($line);
        }

        return json_decode($line) !== null || $line === 'null';
    }
}
