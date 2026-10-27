<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use MyBeast\Logs\Laravel\Facades\Logs;

/**
 * Requests through the demo host's HTTP kernel, as a web server would hand them over: how long each took to answer
 * (the time a person waits), and apart from that how long its after-response work took (where the SDK sends -
 * PHP-FPM has answered by then). Run it with the service up, then down: the answers take the same time, and the
 * lines wait in the spool until it is back.
 */
final class DemoTraffic extends Command
{
    protected $signature = 'mybeast:logs-demo {--requests=40 : How many checkouts} {--from=1 : The first order number}';

    protected $description = 'Send demo checkouts through the app and time them';

    public function handle(Kernel $kernel): int
    {
        config(['logging.channels.payments' => ['driver' => 'mybeast', 'name' => 'payments']]);

        $answers = [];
        $afters = [];
        $lastId = null;
        $from = (int) $this->option('from');

        for ($order = $from; $order < $from + (int) $this->option('requests'); $order++) {
            $request = Request::create('/demo/checkout', 'GET', ['order' => $order]);

            $started = hrtime(true);
            $response = $kernel->handle($request);
            $answers[] = (hrtime(true) - $started) / 1e6;

            $started = hrtime(true);
            $kernel->terminate($request, $response);
            $afters[] = (hrtime(true) - $started) / 1e6;

            $lastId = $response->headers->get('X-Request-Id');
        }

        sort($answers);
        sort($afters);
        $this->table(['', 'median', 'slowest'], [
            ['Answering a request (ms)', self::ms($answers[intdiv(count($answers), 2)]), self::ms(end($answers))],
            ['After the response (ms)', self::ms($afters[intdiv(count($afters), 2)]), self::ms(end($afters))],
        ]);

        $state = Logs::spool()->state();
        $this->line(count($answers)." checkouts; the last one's request id: {$lastId}");
        $this->line(isset($state['failing_since'])
            ? 'The service is not taking lines ('.$state['last_error'].'): they wait in '.Logs::spool()->directory()
            : 'The service takes lines; what is left goes with the next round (mybeast:logs-ship).');

        return self::SUCCESS;
    }

    private static function ms(float $ms): string
    {
        return number_format($ms, 2);
    }
}
