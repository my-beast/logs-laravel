<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Scope;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

/**
 * Which request (or job, or command run) a line belongs to: its id and what was running. A frame per run, stacked -
 * a sync job runs inside the request that queued it, and the request goes on after it.
 *
 * The id goes along to the jobs a run queues (Laravel's hidden Context, Laravel 11+), so a job's lines carry the id of
 * the request that queued it and "every line of one request" includes them.
 */
final class Scope
{
    /** The hidden Context key the id rides to queued jobs in. */
    public const CONTEXT_KEY = 'mybeast_logs_request_id';

    private const ID = '/^[A-Za-z0-9._:-]{8,64}$/';

    /** @var list<array{id: string, transaction: ?string, kind: string, owner: ?int}> */
    private array $frames = [];

    /** @param  \Closure(): ?Request  $request  the request being handled, when this process handles one */
    public function __construct(
        private readonly \Closure $request,
        private readonly string $header,
    ) {}

    public function requestId(): ?string
    {
        return $this->current()['id'] ?? null;
    }

    public function transaction(): ?string
    {
        return $this->current()['transaction'] ?? null;
    }

    /**
     * An HTTP request: its own frame, the id from the header when one fits. The same request asked twice (a line logged
     * during boot, then the middleware) keeps its frame.
     */
    public function forRequest(Request $request): string
    {
        $top = $this->frames === [] ? null : $this->frames[array_key_last($this->frames)];

        if ($top !== null && $top['kind'] === 'request' && $top['owner'] === spl_object_id($request)) {
            return $top['id'];
        }

        // A long-lived process (Octane) starts each request clean.
        $this->frames = [];

        return $this->push('request', self::accept($request->headers->get($this->header)), null, spl_object_id($request));
    }

    /** A queued job: the id of the run that queued it, when it came along; else its own. */
    public function beginJob(string $name): void
    {
        $this->push('job', self::accept(self::fromContext()), 'job '.$name, null);
    }

    /** @return bool whether no request is running under it (a worker's job, not a sync job inside a request) */
    public function endJob(): bool
    {
        $top = $this->frames === [] ? null : $this->frames[array_key_last($this->frames)];

        if ($top !== null && $top['kind'] === 'job') {
            array_pop($this->frames);
            $this->share();
        }

        return ! in_array('request', array_column($this->frames, 'kind'), true);
    }

    /** An artisan command: its own id (a queue worker's jobs get theirs on top). */
    public function beginCommand(string $name): void
    {
        if ($this->frames === []) {
            $this->push('command', null, 'artisan '.$name, null);
        }
    }

    /** What is running, without values: "GET /properties/{property}". */
    public function name(string $transaction): void
    {
        if ($this->frames !== []) {
            $this->frames[array_key_last($this->frames)]['transaction'] = mb_substr($transaction, 0, 200);
        }
    }

    public function reset(): void
    {
        $this->frames = [];
    }

    /** @return array{id: string, transaction: ?string, kind: string, owner: ?int}|null */
    private function current(): ?array
    {
        if ($this->frames === []) {
            // A line before the middleware ran (a provider's boot): the request is bound already.
            $request = ($this->request)();

            if ($request !== null) {
                $this->forRequest($request);
            }
        }

        return $this->frames === [] ? null : $this->frames[array_key_last($this->frames)];
    }

    private function push(string $kind, ?string $id, ?string $transaction, ?int $owner): string
    {
        $id ??= (string) Str::ulid();
        $this->frames[] = ['id' => $id, 'transaction' => $transaction, 'kind' => $kind, 'owner' => $owner];
        $this->share();

        return $id;
    }

    /** The current id rides along to whatever is queued from here on. */
    private function share(): void
    {
        $id = $this->frames === [] ? null : $this->frames[array_key_last($this->frames)]['id'];

        if ($id === null || ! class_exists(Context::class)) {
            return;
        }

        try {
            Context::addHidden(self::CONTEXT_KEY, $id);
        } catch (Throwable) {
            // Laravel without Context (10.x): jobs get ids of their own.
        }
    }

    private static function fromContext(): ?string
    {
        try {
            $id = class_exists(Context::class) ? Context::getHidden(self::CONTEXT_KEY) : null;
        } catch (Throwable) {
            return null;
        }

        return is_string($id) ? $id : null;
    }

    private static function accept(?string $id): ?string
    {
        return $id !== null && preg_match(self::ID, $id) === 1 ? $id : null;
    }
}
