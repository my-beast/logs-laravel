<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Transport;

use Illuminate\Support\Facades\Http;
use Throwable;

/** The host server talking to the service with its server secret (MYBEAST_LOGS_SECRET). */
final readonly class ServiceClient
{
    public function __construct(
        private string $url,
        private string $secret,
    ) {}

    /**
     * One batch to POST /v1/logs, gzip. The lines are JSON already (as the spool keeps them): the body is put together,
     * not encoded again. The service answers per line: refused ones are named and never sent again.
     *
     * @param  list<string>  $lines
     * @return array{accepted: int, duplicates: int, refused: list<array{id: ?string, reason: string}>}
     */
    public function send(array $lines, int $timeout, int $connectTimeout): array
    {
        $body = (string) gzencode('{"lines":['.implode(',', $lines).']}', 6);

        try {
            $response = Http::withToken($this->secret)
                ->acceptJson()
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withHeaders(['Content-Encoding' => 'gzip'])
                ->withBody($body, 'application/json')
                ->post($this->url.'/v1/logs');
        } catch (Throwable $e) {
            throw new TransportFailed('The service could not be reached: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new TransportFailed('The service answered '.$response->status().': '.mb_substr($response->body(), 0, 300), $response->status());
        }

        $answer = (array) $response->json();

        return [
            'accepted' => (int) ($answer['accepted'] ?? 0),
            'duplicates' => (int) ($answer['duplicates'] ?? 0),
            'refused' => array_values(array_filter((array) ($answer['refused'] ?? []), 'is_array')),
        ];
    }
}
