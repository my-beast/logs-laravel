<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MyBeast\Logs\Laravel\Facades\Logs;

/** The `mybeast` channel: a line goes into the spool, with where it came from, and nothing leaves during the call. */
final class ChannelTest extends TestCase
{
    public function test_a_line_goes_into_the_spool_with_where_it_came_from(): void
    {
        Http::fake();

        Log::warning('Disk 91% full', ['disk' => '/var', 'free_mb' => 812]);

        $lines = $this->spooled();
        $this->assertCount(1, $lines);
        [$line] = $lines;
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $line['id']);
        $this->assertSame('warning', $line['level']);
        $this->assertSame('Disk 91% full', $line['message']);
        $this->assertSame(['disk' => '/var', 'free_mb' => 812], $line['context']);
        $this->assertSame(['app', 'staging', 'abc1234', 'web-1'], [$line['channel'], $line['environment'], $line['release'], $line['host']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}$/', $line['at']);
        $this->assertSame([], Fixtures::violations(['lines' => $lines], 'log-batch.schema.json'));

        // Writing a line is a file append: nothing went over the network.
        Http::assertNothingSent();
    }

    public function test_a_channel_with_a_name_is_sent_as_such(): void
    {
        config(['logging.channels.payments' => ['driver' => 'mybeast', 'name' => 'payments']]);

        Log::channel('payments')->error('The bank answered 503');
        Log::channel('mybeast')->info('Straight to the channel');

        $this->assertSame(['payments', 'app'], array_column($this->spooled(), 'channel'));
    }

    public function test_below_the_channels_level_nothing_is_kept(): void
    {
        config(['logging.channels.quiet' => ['driver' => 'mybeast', 'level' => 'warning']]);

        Log::channel('quiet')->info('Not this');
        Log::channel('quiet')->error('But this');

        $this->assertSame(['But this'], array_column($this->spooled(), 'message'));
    }

    public function test_the_sdks_own_lines_stay_at_home(): void
    {
        Log::warning('MyBeast logs: lines wait in the spool');

        $this->assertSame([], $this->spooled());
    }

    public function test_writing_a_thousand_lines_takes_no_time_worth_a_request(): void
    {
        $started = hrtime(true);

        for ($i = 0; $i < 1000; $i++) {
            Log::info("Line {$i}", ['i' => $i, 'user' => 'u'.$i]);
        }

        $ms = (hrtime(true) - $started) / 1e6;
        $this->assertCount(1000, $this->spooled());
        // About 0.05 ms a line on a laptop; the bound is loose so a busy CI box passes too.
        $this->assertLessThan(2000, $ms, "1000 lines took {$ms} ms");
    }

    public function test_a_spool_that_cannot_be_written_never_breaks_the_app(): void
    {
        file_put_contents($this->spool, 'a file where the folder should be');

        Log::error('Still fine');

        $this->assertFileExists($this->spool);
        $this->assertTrue(true, 'no exception reached the app');
    }

    public function test_off_without_a_url_or_secret(): void
    {
        config(['mybeast-logs.secret' => null]);
        $this->refreshApplication();
        config(['mybeast-logs.secret' => null, 'mybeast-logs.spool_path' => $this->spool]);
        app()->forgetInstance('log');

        Log::channel('mybeast')->error('Nowhere to go');

        $this->assertSame([], $this->spooled());
        $this->assertFalse(Logs::enabled());
        $this->artisan('mybeast:logs-ship')->expectsOutputToContain('MyBeast logs is off')->assertSuccessful();
    }
}
