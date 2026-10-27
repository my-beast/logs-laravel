<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Tests;

use Illuminate\Http\Client\Request;
use MyBeast\Logs\Laravel\LogsServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    protected const SECRET = 'sk_abcdefghijklmnop.0123456789abcdefghijABCDEFGHIJ0123456789abc';

    protected string $spool;

    protected function getPackageProviders($app): array
    {
        return [LogsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->spool = sys_get_temp_dir().'/mybeast-logs-spool-'.bin2hex(random_bytes(4));
        $app['config']->set('mybeast-logs.url', 'https://logs.example.test/');
        $app['config']->set('mybeast-logs.secret', self::SECRET);
        $app['config']->set('mybeast-logs.environment', 'Staging');
        $app['config']->set('mybeast-logs.release', 'abc1234');
        $app['config']->set('mybeast-logs.host', 'web-1');
        $app['config']->set('mybeast-logs.spool_path', $this->spool);
        $app['config']->set('logging.default', 'stack');
        $app['config']->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['mybeast']]);
    }

    protected function tearDown(): void
    {
        self::remove($this->spool);
        parent::tearDown();
    }

    /**
     * Every line waiting in the spool, oldest first, decoded.
     *
     * @return list<array<string, mixed>>
     */
    protected function spooled(): array
    {
        $files = [...(glob($this->spool.'/ready/*.ndjson') ?: []), $this->spool.'/current.ndjson'];
        $lines = [];

        foreach ($files as $file) {
            $offset = (int) @file_get_contents($file.'.offset');
            $text = substr((string) @file_get_contents($file), $offset);

            foreach (array_filter(explode("\n", $text)) as $line) {
                $lines[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $lines;
    }

    /** @return list<array<string, mixed>> the lines an upload carried */
    protected static function sentLines(Request $request): array
    {
        return json_decode((string) gzdecode($request->body()), true, flags: JSON_THROW_ON_ERROR)['lines'];
    }

    private static function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path.'/'.$entry);
                }
            }

            @rmdir($path);
        } elseif (file_exists($path)) {
            @unlink($path);
        }
    }
}
