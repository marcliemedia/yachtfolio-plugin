<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Support;

/**
 * Central passkey scrubber.
 *
 * Every gallery URL from the API embeds the passkey, so nothing that comes back
 * from Yachtfolio may reach a log row, a stored payload, an admin notice or a
 * CSV export unfiltered.
 */
final class Secrets
{
    /** @var array<int,string> */
    private static array $secrets = [];

    public static function register(?string $secret): void
    {
        $secret = trim((string) $secret);
        if ($secret !== '' && strlen($secret) >= 8 && !in_array($secret, self::$secrets, true)) {
            self::$secrets[] = $secret;
        }
    }

    public static function reset(): void
    {
        self::$secrets = [];
    }

    /**
     * @param mixed $value
     * @return mixed same shape, secrets replaced
     */
    public static function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            $out = $value;
            foreach (self::$secrets as $secret) {
                $out = str_replace($secret, '<PASSKEY>', $out);
            }
            // Catch any api=/passkey= parameter even when the key is unknown here.
            return (string) preg_replace('/(\b(?:api|passkey)=)[A-Za-z0-9]{8,}/i', '$1<PASSKEY>', $out);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[is_string($k) ? (string) self::scrub($k) : $k] = self::scrub($v);
            }
            return $out;
        }

        if ($value instanceof \Throwable) {
            return self::scrub($value->getMessage());
        }

        return $value;
    }

    public static function mask(string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '') {
            return '';
        }
        if (strlen($secret) <= 4) {
            return str_repeat('•', strlen($secret));
        }
        return str_repeat('•', 8) . substr($secret, -4);
    }
}
