<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use MyBeast\Logs\Laravel\Facades\Logs;
use Workbench\App\Jobs\SendsReceipt;

/*
 * A checkout that logs the way apps do - personal data included, for the SDK to take out before anything leaves.
 * Every 10th order is declined (an error with its exception), every 4th is slow (a warning).
 */
Route::get('/demo/checkout', function (Request $request) {
    $order = (int) $request->query('order', (string) random_int(1000, 9999));

    Log::info("Checkout started for order {$order} by anna.virtanen@example.fi", ['order' => $order, 'items' => 3]);
    Log::debug('Payment details', ['order' => $order, 'iban' => 'FI21 1234 5600 0007 85', 'card_number' => '4111 1111 1111 1111', 'hetu' => '131052-308T']);

    if ($order % 4 === 0) {
        Log::warning('The payment provider answered slowly', ['order' => $order, 'ms' => 812]);
    }

    if ($order % 10 === 0) {
        Log::channel('payments')->error("Card declined for order {$order}", ['exception' => new RuntimeException('Card declined: insufficient funds (call +358 40 123 4567)')]);

        return response()->json(['order' => $order, 'paid' => false, 'request_id' => Logs::requestId()], 402);
    }

    SendsReceipt::dispatch($order);
    Log::info("Order {$order} paid");

    return response()->json(['order' => $order, 'paid' => true, 'request_id' => Logs::requestId()]);
});
