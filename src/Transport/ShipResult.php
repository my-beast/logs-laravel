<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Transport;

/** What one round of sending did. `busy`: another process was sending, so this one did not. */
final readonly class ShipResult
{
    public function __construct(
        public int $sent = 0,
        public int $refused = 0,
        public int $waitingBytes = 0,
        public ?string $error = null,
        public bool $busy = false,
    ) {}

    public static function busy(): self
    {
        return new self(busy: true);
    }
}
