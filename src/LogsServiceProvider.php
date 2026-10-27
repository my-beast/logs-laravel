<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use MyBeast\Logs\Laravel\Channel\LineBuilder;
use MyBeast\Logs\Laravel\Channel\SpoolHandler;
use MyBeast\Logs\Laravel\Console\ShipCommand;
use MyBeast\Logs\Laravel\Console\StatusCommand;
use MyBeast\Logs\Laravel\Http\AssignRequestId;
use MyBeast\Logs\Laravel\Scope\Release;
use MyBeast\Logs\Laravel\Scope\Scope;
use MyBeast\Logs\Laravel\Scrubbing\ContextNormalizer;
use MyBeast\Logs\Laravel\Scrubbing\Scrubber;
use MyBeast\Logs\Laravel\Spool\Spool;
use MyBeast\Logs\Laravel\Transport\ServiceClient;
use MyBeast\Logs\Laravel\Transport\Shipper;
use Throwable;

/**
 * Plugs MyBeast logs in without the host writing code: the `mybeast` log channel (add it to the stack), a request id
 * on every request and the jobs it queues, and the spool sent after each response and job and every few seconds by
 * the scheduler. Off (no URL or secret, or MYBEAST_LOGS_ENABLED=false): the channel takes lines and keeps none.
 */
final class LogsServiceProvider extends ServiceProvider
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /** ship_every_seconds → the scheduler's own word for it. */
    private const EVERY = [
        1 => 'everySecond', 2 => 'everyTwoSeconds', 5 => 'everyFiveSeconds', 10 => 'everyTenSeconds',
        15 => 'everyFifteenSeconds', 20 => 'everyTwentySeconds', 30 => 'everyThirtySeconds', 60 => 'everyMinute',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mybeast-logs.php', 'mybeast-logs');

        // The host's scrub rules live in a static list (Scrubber runs where the container may be gone): each app starts
        // it empty, so a test suite booting thousands of apps does not pile them up.
        Scrubber::forget();

        $config = fn (): array => (array) $this->app['config']->get('mybeast-logs', []);

        $this->app->singleton(Scope::class, fn (Application $app): Scope => new Scope(
            fn (): ?Request => ! $app->runningInConsole() && $app->bound('request') ? $app->make('request') : null,
            (string) ($config()['request_id_header'] ?? 'X-Request-Id'),
        ));
        $this->app->singleton(Spool::class, fn (): Spool => new Spool(
            (string) $config()['spool_path'],
            max(1, (int) ($config()['spool_max_mb'] ?? 100)) * 1024 * 1024,
        ));
        $this->app->singleton(ContextNormalizer::class, fn (Application $app): ContextNormalizer => new ContextNormalizer($app->basePath()));
        $this->app->singleton(LineBuilder::class, fn (Application $app): LineBuilder => new LineBuilder(
            $app->make(Scope::class),
            $app->make(ContextNormalizer::class),
            environment: self::environment((string) ($config()['environment'] ?? 'production')),
            release: Release::current(is_string($config()['release'] ?? null) ? $config()['release'] : null, $app->basePath()),
            host: mb_substr((string) (($config()['host'] ?? null) ?: gethostname() ?: 'unknown'), 0, 120),
            appEnvironment: (string) $app->environment(),
        ));
        $this->app->singleton(Shipper::class, fn (Application $app): Shipper => new Shipper(
            $app->make(Spool::class),
            self::on($config()) ? new ServiceClient(rtrim((string) $config()['url'], '/'), (string) $config()['secret']) : null,
            max(1, min(1000, (int) ($config()['batch_size'] ?? 500))),
            fn (string $level, string $message): string => $app->make(LineBuilder::class)->notice($level, $message),
        ));
        $this->app->singleton(Logs::class);
        $this->app->bind(AssignRequestId::class, fn (Application $app): AssignRequestId => new AssignRequestId(
            $app->make(Scope::class),
            (string) ($config()['request_id_header'] ?? 'X-Request-Id'),
        ));

        // The channel. A host that names it in config/logging.php keeps its own; else LOG_STACK=single,mybeast is all.
        if ($this->app['config']->get('logging.channels.mybeast') === null) {
            $level = strtolower((string) ($config()['level'] ?? 'debug'));
            $this->app['config']->set('logging.channels.mybeast', ['driver' => 'mybeast', 'level' => in_array($level, self::LEVELS, true) ? $level : 'debug']);
        }

        $this->callAfterResolving('log', function (LogManager $log) use ($config): void {
            // Laravel binds this to the LogManager (so not static, and self:: would be the LogManager's): the provider is named.
            $log->extend('mybeast', function (Application $app, array $channel) use ($config): Logger {
                $level = strtolower((string) ($channel['level'] ?? 'debug'));
                $handler = LogsServiceProvider::on($config())
                    ? new SpoolHandler($app->make(LineBuilder::class), $app->make(Spool::class), in_array($level, LogsServiceProvider::LEVELS, true) ? $level : 'debug')
                    : new NullHandler;

                return new Logger((string) ($channel['name'] ?? $app->environment()), [$handler]);
            });
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/mybeast-logs.php' => config_path('mybeast-logs.php')], 'mybeast-logs-config');

        if ($this->app->runningInConsole()) {
            $this->commands([ShipCommand::class, StatusCommand::class]);
        }

        Scrubber::usePatterns((array) config('mybeast-logs.scrub', []));

        if (! self::on((array) config('mybeast-logs', []))) {
            return;
        }

        $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel): void {
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(AssignRequestId::class);
            }
        });

        // Resolved on first use, then kept: these run on every request and job.
        $scope = null;
        $current = function () use (&$scope): Scope {
            return $scope ??= $this->app->make(Scope::class);
        };

        Event::listen(RouteMatched::class, function (RouteMatched $event) use ($current): void {
            $current()->name($event->request->method().' /'.ltrim($event->route->uri(), '/'));
        });
        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($current): void {
            $current()->beginJob($event->job->resolveName());
        });
        // Each JobProcessing ends in one of these two (a failed job's JobFailed comes between, not instead). A worker's
        // job sends its lines after it; a sync job inside a request leaves that to the request's end - never mid-request.
        Event::listen([JobProcessed::class, JobExceptionOccurred::class], function () use ($current): void {
            if ($current()->endJob()) {
                $this->shipQuietly();
            }
        });
        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($current): void {
            if ($event->command !== null) {
                $current()->beginCommand($event->command);
            }
        });

        // Sent right after the response went out (PHP-FPM has already answered), and after each command.
        $this->app->terminating(fn () => $this->shipQuietly());

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $every = self::EVERY[(int) config('mybeast-logs.ship_every_seconds', 10)] ?? 'everyTenSeconds';
            $schedule->command('mybeast:logs-ship')->{$every}()->withoutOverlapping()->runInBackground();
        });
    }

    /** On: switched on, an http(s) URL and a server secret that looks like one. */
    public static function on(array $config): bool
    {
        $url = $config['url'] ?? null;
        $secret = $config['secret'] ?? null;

        return ($config['enabled'] ?? true) !== false
            && is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url) === 1
            && is_string($secret) && preg_match('/^sk_[a-z0-9]{8,32}\.[A-Za-z0-9_-]{20,}$/', $secret) === 1;
    }

    private static function environment(string $name): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower($name)), '-');

        return $name === '' ? 'production' : substr($name, 0, 32);
    }

    private function shipQuietly(): void
    {
        if (! config('mybeast-logs.ship_after_response', true)) {
            return;
        }

        try {
            $this->app->make(Shipper::class)->shipSoon();
        } catch (Throwable) {
            // The scheduler's round will get it.
        }
    }
}
