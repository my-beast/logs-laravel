<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Console\DemoTraffic;

/** The demo host's own pieces: its routes come from workbench/routes/web.php (testbench discovers them). */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DemoTraffic::class]);
        }
    }
}
