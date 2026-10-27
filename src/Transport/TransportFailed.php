<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Transport;

use RuntimeException;

/** The service did not take a batch: no answer (status 0), or an answer that is not a yes. */
final class TransportFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
