<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MyBeast\Logs\Laravel\Facades\Logs;
use MyBeast\Logs\Laravel\Spool\Spool;
use MyBeast\Logs\Laravel\Transport\Shipper;

/** From the spool to the service: in order, in batches, nothing lost while it is away and nothing sent twice. */
final class ShipTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // These tests send by hand: no short round after each artisan call.
        $app['config']->set('mybeast-logs.ship_after_response', false);
    }

    public function test_lines_go_in_batches_gzip_with_the_secret_and_leave_the_spool(): void
    {
        config(['mybeast-logs.batch_size' => 500]);
        $this->app->forgetInstance(Shipper::class);
        Http::fake(['logs.example.test/*' => fn (Request $request) => Http::response(['accepted' => count(self::sentLines($request)), 'duplicates' => 0, 'refused' => []], 202)]);

        for ($i = 1; $i <= 1200; $i++) {
            Log::info("line {$i}");
        }

        $this->artisan('mybeast:logs-ship')->expectsOutputToContain('Sent 1200 line(s).')->assertSuccessful();

        $sent = Http::recorded()->map(fn (array $pair): array => self::sentLines($pair[0]));
        $this->assertSame([500, 500, 200], $sent->map(fn (array $lines): int => count($lines))->all());
        $this->assertSame(array_map(fn (int $i): string => "line {$i}", range(1, 1200)), $sent->flatten(1)->pluck('message')->all());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://logs.example.test/v1/logs'
            && $request->hasHeader('Authorization', 'Bearer '.self::SECRET)
            && $request->hasHeader('Content-Encoding', 'gzip'));
        $this->assertSame([], Fixtures::violations(['lines' => self::sentLines(Http::recorded()->first()[0])], 'log-batch.schema.json'));
        $this->assertSame([], $this->spooled());
    }

    public function test_while_the_service_is_away_nothing_is_lost_and_nothing_comes_twice(): void
    {
        $up = false;
        $stored = [];
        Http::fake(['logs.example.test/*' => function (Request $request) use (&$up, &$stored) {
            if (! $up) {
                throw new ConnectionException('Connection refused');
            }

            $lines = self::sentLines($request);
            $stored = [...$stored, ...array_column($lines, 'message')];

            return Http::response(['accepted' => count($lines), 'duplicates' => 0, 'refused' => []], 202);
        }]);
        // The app writes through the real channel; the SDK's own words go to the spy.
        $channel = Log::channel('mybeast');
        Log::spy();

        // Down: every round fails, the lines wait, and more keep coming.
        for ($round = 1; $round <= 3; $round++) {
            foreach (range(1, 10) as $i) {
                $channel->info("round {$round} line {$i}");
            }

            $this->artisan('mybeast:logs-ship')->expectsOutputToContain('The rest waits')->assertFailed();
        }

        $this->assertCount(30, $this->spooled());
        // A long outage leaves one file waiting and the journal, not a file per round.
        $this->assertCount(1, glob($this->spool.'/ready/*.ndjson'));
        $this->assertNotNull(Logs::spool()->state()['failing_since'] ?? null);
        // Said once when it started failing, not on every round.
        Log::shouldHaveReceived('warning')->with('MyBeast logs: lines wait in the spool, the service did not take them', \Mockery::any())->once();

        // Up again: everything, in the order written, once.
        $up = true;
        $this->artisan('mybeast:logs-ship')->expectsOutputToContain('Sent 30 line(s).')->assertSuccessful();

        $expected = [];
        foreach (range(1, 3) as $round) {
            foreach (range(1, 10) as $i) {
                $expected[] = "round {$round} line {$i}";
            }
        }
        $this->assertSame($expected, $stored);
        $this->assertSame([], $this->spooled());
        $this->assertArrayNotHasKey('failing_since', Logs::spool()->state());
        Log::shouldHaveReceived('info')->with('MyBeast logs: the service takes lines again', \Mockery::any())->once();
    }

    public function test_a_batch_cut_short_by_a_failure_goes_on_where_it_stopped(): void
    {
        config(['mybeast-logs.batch_size' => 2]);
        $this->app->forgetInstance(Shipper::class);
        $calls = 0;
        $stored = [];
        Http::fake(['logs.example.test/*' => function (Request $request) use (&$calls, &$stored) {
            if (++$calls === 2) {
                return Http::response('upstream away', 503);
            }

            $stored = [...$stored, ...array_column(self::sentLines($request), 'message')];

            return Http::response(['accepted' => 2, 'duplicates' => 0, 'refused' => []], 202);
        }]);

        foreach (['a', 'b', 'c', 'd', 'e'] as $message) {
            Log::info($message);
        }

        $this->artisan('mybeast:logs-ship')->assertFailed();
        $this->assertSame(['a', 'b'], $stored);
        $this->assertSame(['c', 'd', 'e'], array_column($this->spooled(), 'message'));

        $this->artisan('mybeast:logs-ship')->assertSuccessful();
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $stored);
    }

    public function test_a_revoked_secret_keeps_the_lines(): void
    {
        Http::fake(['logs.example.test/*' => Http::response(['message' => 'unauthenticated'], 401)]);

        Log::info('kept');
        $this->artisan('mybeast:logs-ship')->expectsOutputToContain('answered 401')->assertFailed();

        $this->assertSame(['kept'], array_column($this->spooled(), 'message'));
    }

    public function test_too_big_goes_again_in_halves(): void
    {
        $sizes = [];
        Http::fake(['logs.example.test/*' => function (Request $request) use (&$sizes) {
            $sizes[] = $count = count(self::sentLines($request));

            return $count > 100
                ? Http::response(['message' => 'Too many lines in one upload'], 413)
                : Http::response(['accepted' => $count, 'duplicates' => 0, 'refused' => []], 202);
        }]);

        for ($i = 0; $i < 300; $i++) {
            Log::info("line {$i}");
        }

        $this->artisan('mybeast:logs-ship')->expectsOutputToContain('Sent 300 line(s).')->assertSuccessful();
        $this->assertSame([300, 150, 75, 75, 75, 75], $sizes);
    }

    public function test_a_body_refused_whole_is_set_aside_and_the_rest_goes_on(): void
    {
        config(['mybeast-logs.batch_size' => 1]);
        $this->app->forgetInstance(Shipper::class);
        Http::fake(['logs.example.test/*' => function (Request $request) {
            return self::sentLines($request)[0]['message'] === 'poison'
                ? Http::response(['message' => 'Not JSON.'], 422)
                : Http::response(['accepted' => 1, 'duplicates' => 0, 'refused' => []], 202);
        }]);

        Log::info('poison');
        Log::info('fine');

        $this->artisan('mybeast:logs-ship')->assertSuccessful();
        $this->assertSame([], $this->spooled());
        $failed = glob($this->spool.'/failed/*.ndjson');
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('"poison"', (string) file_get_contents($failed[0]));
        Http::assertSentCount(2);
    }

    public function test_lines_the_service_refuses_one_by_one_are_not_sent_again(): void
    {
        Http::fake(['logs.example.test/*' => Http::response(['accepted' => 1, 'duplicates' => 0, 'refused' => [['id' => 'x', 'reason' => 'at: older than the 14 days this environment keeps lines']]], 202)]);
        $channel = Log::channel('mybeast');
        Log::spy();

        $channel->info('one');
        $channel->info('two');

        $this->artisan('mybeast:logs-ship')->expectsOutputToContain('The service refused 1.')->assertSuccessful();
        $this->assertSame([], $this->spooled());
        Log::shouldHaveReceived('warning')->with('MyBeast logs: the service refused a line', ['id' => 'x', 'reason' => 'at: older than the 14 days this environment keeps lines'])->once();
    }

    public function test_past_the_months_limit_lines_are_dropped_and_said_once_a_month(): void
    {
        $answers = [
            ['accepted' => 0, 'duplicates' => 0, 'refused' => [['id' => 'a', 'reason' => 'monthly-limit'], ['id' => 'b', 'reason' => 'monthly-limit']]],
            ['accepted' => 0, 'duplicates' => 0, 'refused' => [['id' => 'c', 'reason' => 'monthly-limit'], ['id' => 'd', 'reason' => 'level']]],
            ['accepted' => 1, 'duplicates' => 0, 'refused' => []],
        ];
        Http::fake(['logs.example.test/*' => function () use (&$answers) {
            return Http::response(array_shift($answers), 202);
        }]);
        $channel = Log::channel('mybeast');
        Log::spy();

        $channel->info('one');
        $channel->info('two');
        Logs::ship();
        $channel->info('three');
        $channel->info('four');
        Logs::ship();

        // Dropped, not kept: the service would refuse them again until the 1st. Said once, not once a line; a line
        // refused for a reason of its own still has its warning.
        $this->assertSame([], $this->spooled());
        Log::shouldHaveReceived('warning')->with("MyBeast logs: the project has used up this month's lines; the service drops the rest until the 1st (UTC).", ['dropped' => 2])->once();
        Log::shouldHaveReceived('warning')->with('MyBeast logs: the service refused a line', ['id' => 'd', 'reason' => 'level'])->once();
        Log::shouldNotHaveReceived('warning', ['MyBeast logs: the service refused a line', ['id' => 'a', 'reason' => 'monthly-limit']]);

        $channel->info('five');
        Logs::ship();
        Log::shouldHaveReceived('info')->with('MyBeast logs: the service takes lines again.')->once();
        $this->assertArrayNotHasKey('monthly_limit', app(Spool::class)->state());
    }

    public function test_a_full_spool_keeps_no_more_and_says_so_when_there_is_room(): void
    {
        config(['mybeast-logs.spool_max_mb' => 1]);
        $this->app->forgetInstance(Spool::class);
        $this->app->forgetInstance(Shipper::class);
        $this->app->forgetInstance('log');
        $up = false;
        Http::fake(['logs.example.test/*' => function (Request $request) use (&$up) {
            if (! $up) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(['accepted' => count(self::sentLines($request)), 'duplicates' => 0, 'refused' => []], 202);
        }]);

        $text = str_repeat('a few words ', 100);
        for ($i = 0; $i < 1000; $i++) {
            Log::info("{$i} {$text}");
        }

        $this->artisan('mybeast:logs-ship')->assertFailed();
        $this->assertNotNull(Logs::spool()->fullSince());
        $waiting = count($this->spooled());

        Log::info('not kept: the spool is full');
        $this->assertCount($waiting, $this->spooled());

        $up = true;
        $this->artisan('mybeast:logs-ship')->assertSuccessful();
        $this->assertNull(Logs::spool()->fullSince());

        // The next round carries the line that says what was not kept.
        $this->artisan('mybeast:logs-ship')->assertSuccessful();
        $last = self::sentLines(Http::recorded()->last()[0]);
        $this->assertSame('mybeast-logs', $last[0]['channel']);
        $this->assertStringContainsString('the spool was full from', $last[0]['message']);
    }

    public function test_one_sender_at_a_time(): void
    {
        Http::fake();
        Log::info('waits for the one that sends');

        $result = Logs::spool()->exclusively(fn () => Logs::ship());

        $this->assertTrue($result->busy);
        Http::assertNothingSent();
    }
}
