<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Scope;

/** The release a process runs: config('mybeast-logs.release') (MYBEAST_LOGS_RELEASE / APP_RELEASE), else the git commit, read from .git. */
final class Release
{
    private static ?string $resolved = null;

    private static bool $read = false;

    public static function current(?string $configured, string $basePath): ?string
    {
        if ($configured !== null && $configured !== '') {
            return mb_substr($configured, 0, 80);
        }

        // Reading .git costs file I/O: once per process is enough.
        if (self::$read) {
            return self::$resolved;
        }

        self::$read = true;

        return self::$resolved = self::fromGit($basePath);
    }

    public static function forget(): void
    {
        self::$read = false;
        self::$resolved = null;
    }

    private static function fromGit(string $basePath): ?string
    {
        $head = @file_get_contents($basePath.'/.git/HEAD');

        if ($head === false) {
            return null;
        }

        $head = trim($head);

        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            $sha = @file_get_contents($basePath.'/.git/'.$ref);

            if ($sha === false && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', (string) @file_get_contents($basePath.'/.git/packed-refs'), $m)) {
                $sha = $m[1];
            }

            $head = trim((string) $sha);
        }

        return preg_match('/^[0-9a-f]{7,40}$/', $head) === 1 ? substr($head, 0, 12) : null;
    }
}
