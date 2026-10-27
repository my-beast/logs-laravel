<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use MyBeast\Logs\Laravel\Spool\Spool;
use MyBeast\Logs\Laravel\Transport\ShipResult;

/**
 * @method static bool enabled()
 * @method static string|null requestId()
 * @method static void scrubUsing(Closure $rule)
 * @method static ShipResult ship()
 * @method static Spool spool()
 *
 * @see \MyBeast\Logs\Laravel\Logs
 */
final class Logs extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MyBeast\Logs\Laravel\Logs::class;
    }
}
