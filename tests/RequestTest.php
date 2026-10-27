<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Tests;

use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MyBeast\Logs\Laravel\Facades\Logs;
use MyBeast\Logs\Laravel\Scope\Scope;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/** One request's lines: one id, what was running, sent after the response - and the jobs it queued carry the id too. */
final class RequestTest extends TestCase
{
    /** @var list<int> requests to the service seen while the route ran */
    public static array $sentDuringRequest = [];

    protected function defineRoutes($router): void
    {
        $router->get('/invoices/{invoice}/send', function (string $invoice) {
            Log::info("Sending invoice {$invoice}");
            SendsInvoice::dispatch($invoice);
            Log::info('Sent', ['invoice' => $invoice]);
            RequestTest::$sentDuringRequest[] = count(Http::recorded());

            return response('ok');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$sentDuringRequest = [];
    }

    public function test_every_line_of_a_request_carries_its_id_and_what_ran(): void
    {
        Http::fake(['logs.example.test/*' => fn (Request $request) => Http::response(['accepted' => count(self::sentLines($request)), 'duplicates' => 0, 'refused' => []], 202)]);

        $response = $this->get('/invoices/1042/send')->assertOk();

        $id = (string) $response->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id);

        // Nothing went over the network while the route ran; right after the response, one short round sent them all.
        $this->assertSame([0], self::$sentDuringRequest);
        Http::assertSentCount(1);
        $lines = self::sentLines(Http::recorded()->first()[0]);
        $this->assertSame(['Sending invoice 1042', 'Invoice 1042 sent by the job', 'Sent'], array_column($lines, 'message'));
        $this->assertSame([$id, $id, $id], array_column($lines, 'request_id'));
        $this->assertSame(['GET /invoices/{invoice}/send', 'job '.SendsInvoice::class, 'GET /invoices/{invoice}/send'], array_column($lines, 'transaction'));
        $this->assertSame([], $this->spooled());
    }

    public function test_a_proxys_request_id_is_kept_when_it_fits(): void
    {
        Http::fake();

        $this->get('/invoices/1/send', ['X-Request-Id' => 'lb-7f3a9c21e5'])->assertHeader('X-Request-Id', 'lb-7f3a9c21e5');
        $this->get('/invoices/2/send', ['X-Request-Id' => 'no spaces <script>'])->assertOk();

        $ids = array_column(Http::recorded()->flatMap(fn (array $pair): array => self::sentLines($pair[0]))->all(), 'request_id');
        $this->assertSame('lb-7f3a9c21e5', $ids[0]);
        $this->assertNotContains('no spaces <script>', $ids);
    }

    public function test_while_the_service_fails_a_request_does_not_wait_on_it(): void
    {
        $calls = 0;
        Http::fake(['logs.example.test/*' => function () use (&$calls) {
            $calls++;

            throw new ConnectionException('Connection refused');
        }]);
        Log::info('before');
        $this->artisan('mybeast:logs-ship')->assertFailed();
        $this->assertSame(1, $calls);

        $this->get('/invoices/1042/send')->assertOk();

        // The failing service is the scheduler's to keep trying: the request's own round did not call it.
        $this->assertSame(1, $calls);
        $this->assertSame(['before', 'Sending invoice 1042', 'Invoice 1042 sent by the job', 'Sent'], array_column($this->spooled(), 'message'));
    }

    public function test_a_job_from_a_queue_worker_carries_the_id_of_the_request_that_queued_it(): void
    {
        // The request queued it: its hidden context rode in the payload. The worker is another process - nothing else.
        Context::addHidden(Scope::CONTEXT_KEY, '01JBFX3W2KQ7S3VZ0H5M9N2P4R');
        $payload = ['illuminate:log:context' => Context::dehydrate()];
        Context::flush();
        $this->app->make(Scope::class)->reset();

        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn(SendsInvoice::class);
        $job->shouldReceive('payload')->andReturn($payload);

        event(new JobProcessing('database', $job));
        Log::info('in the job');
        $this->assertSame('01JBFX3W2KQ7S3VZ0H5M9N2P4R', Logs::requestId());
        event(new JobProcessed('database', $job));

        [$line] = $this->spooled();
        $this->assertSame(['01JBFX3W2KQ7S3VZ0H5M9N2P4R', 'job '.SendsInvoice::class], [$line['request_id'], $line['transaction']]);
    }

    public function test_a_command_run_has_an_id_of_its_own(): void
    {
        // Laravel fires CommandStarting for a real `php artisan …`, not under PHPUnit: the event comes by hand here.
        event(new CommandStarting('invoices:remind', new ArrayInput([]), new NullOutput));
        Log::info('Reminded 3');

        [$line] = $this->spooled();
        $this->assertSame('artisan invoices:remind', $line['transaction']);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $line['request_id']);
    }
}

/** A job a request queues (sync here): its lines are part of the request's story. */
final class SendsInvoice implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(
        public string $invoice,
    ) {}

    public function handle(): void
    {
        Log::info("Invoice {$this->invoice} sent by the job");
    }
}
