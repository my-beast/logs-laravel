<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use MyBeast\Logs\Laravel\Scope\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * First of the global middleware: the request's id (from the proxy's header when one fits, else a new one), on every
 * line of the request and on its response, so whoever saw a problem can quote it.
 */
final readonly class AssignRequestId
{
    public function __construct(
        private Scope $scope,
        private string $header,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->scope->forRequest($request);
        $response = $next($request);

        if (! $response->headers->has($this->header)) {
            $response->headers->set($this->header, $id);
        }

        return $response;
    }
}
