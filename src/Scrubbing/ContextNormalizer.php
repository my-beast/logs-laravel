<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Scrubbing;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * A line's context (and extra) as plain data the service can keep: every string scrubbed, a value under a
 * secret-looking key gone, an exception as its class, message and where it was thrown, a model as its class and key
 * only - never its attributes (they are people's data). Deep, long or big context is cut, and says so.
 */
final class ContextNormalizer
{
    private const MAX_DEPTH = 6;

    private const MAX_ITEMS = 100;

    private const MAX_STRING = 4000;

    private const MAX_FRAMES = 15;

    /** What one line's context may weigh as JSON before it is cut down (the service keeps up to 64 KiB). */
    private const MAX_BYTES = 16384;

    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>|null null when there is nothing
     */
    public function normalize(array $data): ?array
    {
        if ($data === []) {
            return null;
        }

        $out = $this->array($data, 0);
        $json = (string) json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (strlen($json) <= self::MAX_BYTES) {
            return $out;
        }

        // Too big: the exception is what matters most, so it stays when it fits.
        $cut = ['_cut' => 'The context was '.(int) ceil(strlen($json) / 1024).' KB; a line carries '.(self::MAX_BYTES / 1024).' KB.'];

        if (isset($out['exception']) && strlen((string) json_encode($out['exception'])) < self::MAX_BYTES - 200) {
            $cut['exception'] = $out['exception'];
        }

        return $cut;
    }

    private function value(mixed $value, int $depth): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : (string) $value,
            is_string($value) => $this->string($value),
            is_array($value) => $depth >= self::MAX_DEPTH ? '[…]' : $this->array($value, $depth + 1),
            $value instanceof Throwable => $depth >= self::MAX_DEPTH ? get_class($value) : $this->throwable($value, $depth + 1),
            $value instanceof DateTimeInterface => $value->format('Y-m-d\TH:i:s.uP'),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            // A model's attributes are a person's data: its class and key say which row, and that is enough.
            $value instanceof Model => ['model' => get_class($value), 'key' => $value->getKey()],
            $value instanceof JsonSerializable => $this->value($value->jsonSerialize(), $depth),
            $value instanceof Arrayable => $this->value($value->toArray(), $depth),
            $value instanceof Stringable => $this->string((string) $value),
            is_object($value) => '[object '.get_class($value).']',
            default => '['.get_debug_type($value).']',
        };
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function array(array $data, int $depth): array
    {
        $out = [];
        $count = 0;

        foreach ($data as $key => $item) {
            if (++$count > self::MAX_ITEMS) {
                $out['…'] = (count($data) - self::MAX_ITEMS).' more';
                break;
            }

            $key = is_string($key) ? Scrubber::text($key) : $key;
            $out[$key] = is_string($key) && Scrubber::secretKey($key) ? '[redacted]' : $this->value($item, $depth);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function throwable(Throwable $e, int $depth): array
    {
        $frames = array_map(
            fn (array $frame): string => (isset($frame['file']) ? $this->path($frame['file']).':'.($frame['line'] ?? 0) : '[internal]')
                .(isset($frame['function']) ? ' '.($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].'()' : ''),
            array_slice($e->getTrace(), 0, self::MAX_FRAMES),
        );

        return array_filter([
            'class' => get_class($e),
            'message' => $this->string($e->getMessage()),
            'code' => $e->getCode(),
            'file' => $this->path($e->getFile()).':'.$e->getLine(),
            'trace' => $frames,
            'previous' => $e->getPrevious() === null ? null : $this->value($e->getPrevious(), $depth),
        ], fn (mixed $part): bool => $part !== null && $part !== []);
    }

    private function string(string $value): string
    {
        $value = Scrubber::text($value);

        return strlen($value) > self::MAX_STRING ? mb_strcut($value, 0, self::MAX_STRING).'…' : $value;
    }

    /** The app's own files relative to its root: shorter, and the server's layout stays home. */
    private function path(string $file): string
    {
        return str_starts_with($file, $this->basePath.'/') ? substr($file, strlen($this->basePath) + 1) : $file;
    }
}
