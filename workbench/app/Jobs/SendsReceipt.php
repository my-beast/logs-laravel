<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/** A job the checkout queues: its lines carry the checkout request's id. */
final class SendsReceipt implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(
        public int $order,
    ) {}

    public function handle(): void
    {
        Log::info("Receipt for order {$this->order} sent to matti.meikalainen@example.fi", ['order' => $this->order]);
    }
}
