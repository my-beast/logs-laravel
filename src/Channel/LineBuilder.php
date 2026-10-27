<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Channel;

use Illuminate\Support\Str;
use Monolog\LogRecord;
use MyBeast\Logs\Laravel\Scope\Scope;
use MyBeast\Logs\Laravel\Scrubbing\ContextNormalizer;
use MyBeast\Logs\Laravel\Scrubbing\Scrubber;

/**
 * A Monolog record → one line of the contract (schemas/v1/log-batch.schema.json): scrubbed, with the environment,
 * release, host, channel and the request's id and what was running.
 */
final readonly class LineBuilder
{
    /** The service keeps 32 KiB of a message: no point carrying more over the wire. */
    private const MAX_MESSAGE_BYTES = 32768;

    public function __construct(
        private Scope $scope,
        private ContextNormalizer $context,
        private string $environment,
        private ?string $release,
        private string $host,
        private string $appEnvironment,
    ) {}

    /** @return array<string, mixed> */
    public function build(LogRecord $record): array
    {
        return $this->line(
            level: $record->level->toPsrLogLevel(),
            message: $record->message,
            at: $record->datetime->format('Y-m-d\TH:i:s.uP'),
            channel: $this->channel($record->channel),
            context: $this->context->normalize($record->context),
            extra: $this->context->normalize($record->extra),
        );
    }

    /** A line of the SDK's own (the spool was full): as JSON, ready for the spool. */
    public function notice(string $level, string $message): string
    {
        return self::encode($this->line($level, $message, now()->format('Y-m-d\TH:i:s.uP'), 'mybeast-logs', null, null));
    }

    /** @param  array<string, mixed>  $line */
    public static function encode(array $line): string
    {
        return (string) json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * @param  array<mixed>|null  $context
     * @param  array<mixed>|null  $extra
     * @return array<string, mixed>
     */
    private function line(string $level, string $message, string $at, string $channel, ?array $context, ?array $extra): array
    {
        if (strlen($message) > self::MAX_MESSAGE_BYTES) {
            $message = mb_strcut($message, 0, self::MAX_MESSAGE_BYTES - 3).'…';
        }

        return [
            'id' => (string) Str::ulid(),
            'at' => $at,
            'level' => $level,
            'message' => Scrubber::text($message),
            'context' => $context,
            'extra' => $extra,
            'channel' => $channel,
            'environment' => $this->environment,
            'release' => $this->release,
            'host' => $this->host,
            'request_id' => $this->scope->requestId(),
            'transaction' => $this->scope->transaction() === null ? null : Scrubber::text($this->scope->transaction()),
        ];
    }

    /**
     * Laravel names a channel's Monolog logger after the app's environment unless the channel says `name`: that is no
     * channel, so it is "app". A channel with a name (`'name' => 'payments'`) is sent as such.
     */
    private function channel(string $name): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9._:\/-]+/', '-', $name);

        return $name === '' || $name === $this->appEnvironment || $name === 'mybeast' ? 'app' : mb_substr($name, 0, 64);
    }
}
