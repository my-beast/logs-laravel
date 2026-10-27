<?php

declare(strict_types=1);

namespace MyBeast\Logs\Laravel\Scrubbing;

use Closure;

/**
 * Takes personal data out of every line before it leaves the app: the message, and every string in its context and
 * extra; a value under a secret-looking key (password, token, cookie, …) goes whole. The service runs the same
 * patterns again on arrival (service/app/Logs/Support/Scrubber.php - keep the two the same; the patterns are
 * errors/laravel's).
 *
 * A host adds its own: config('mybeast-logs.scrub') patterns, and Logs::scrubUsing(fn (string $text): string => …).
 */
final class Scrubber
{
    private const PATTERNS = [
        // E-mails first - they hold dots and digits the later rules would chew up.
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '[email]',
        // IBAN, with or without grouping spaces.
        '/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){2,7}(?:\s?[A-Z0-9]{1,4})?\b/' => '[iban]',
        // Finnish personal identity code (henkilötunnus), incl. the 2023 separators.
        '/\b\d{6}[-+A-FU-Y]\d{3}[0-9A-Y]\b/' => '[hetu]',
        // Bearer / API tokens and long secrets.
        '/\b(?:Bearer\s+)[A-Za-z0-9\-._~+\/]+=*/' => 'Bearer [token]',
        '/\b[A-Za-z0-9]{40,}\b/' => '[token]',
        // Phone numbers: +358 40 123 4567, 040-1234567. A leading + or 0 is required, so "134217728 bytes" survives.
        '/(?<![\w+])(?:\+\d{1,3}[\s-]?\d{1,3}|0\d{1,3})[\s-]?\d{3}[\s-]?\d{3,4}(?!\w)/' => '[phone]',
    ];

    /** A key naming one of these (any case, - or _) keeps no value at all, whatever it holds. */
    private const SECRET_KEYS = ['password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'cookie', 'private_key', 'card_number', 'cvv', 'cvc'];

    /** @var array<string, string> the host's patterns (config) */
    private static array $patterns = [];

    /** @var list<Closure(string): string> the host's rules (Logs::scrubUsing) */
    private static array $rules = [];

    /** @param  array<string, string>  $patterns  regex => replacement; one that does not compile is left out */
    public static function usePatterns(array $patterns): void
    {
        self::$patterns = array_filter($patterns, fn (mixed $replacement, mixed $pattern): bool => is_string($pattern)
            && is_string($replacement) && @preg_match($pattern, '') !== false, ARRAY_FILTER_USE_BOTH);
    }

    /** @param  Closure(string): string  $rule */
    public static function extend(Closure $rule): void
    {
        self::$rules[] = $rule;
    }

    public static function forget(): void
    {
        self::$patterns = [];
        self::$rules = [];
    }

    public static function text(string $text): string
    {
        foreach ([...self::PATTERNS, ...self::$patterns] as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        foreach (self::$rules as $rule) {
            $text = (string) $rule($text);
        }

        return $text;
    }

    public static function secretKey(string $key): bool
    {
        $key = str_replace('-', '_', strtolower($key));

        foreach (self::SECRET_KEYS as $secret) {
            if (str_contains($key, $secret)) {
                return true;
            }
        }

        return false;
    }
}
