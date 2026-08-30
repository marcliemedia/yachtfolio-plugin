<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Domain;

/**
 * Content hash used to decide whether a yacht actually changed.
 *
 * Volatile keys are dropped before hashing: the feed's timestamps move on their
 * own, and a hash that follows them would report every yacht as changed on
 * every pass, turning a cheap no-op run into a full rewrite.
 */
final class Hasher
{
    private const VOLATILE = [
        'last_modified',
        'lastModified',
        'crew_modified',
        'date_modified',
        'date_added',
        'imported_at',
        'raw',
    ];

    public static function of(YachtPayload $payload): string
    {
        return self::ofArray($payload->toArray());
    }

    /** @param array<string,mixed> $data */
    public static function ofArray(array $data): string
    {
        $json = wp_json_encode(self::canonical($data));
        return hash('sha256', (string) $json);
    }

    /**
     * @param array<int|string,mixed> $data
     * @return array<int|string,mixed>
     */
    public static function canonical(array $data): array
    {
        $out = [];
        $isList = array_is_list($data);

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::VOLATILE, true)) {
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::canonical($value);
                continue;
            }
            $out[$key] = self::scalar($value);
        }

        if ($isList) {
            $out = array_values($out);
        } else {
            ksort($out);
        }

        return $out;
    }

    /**
     * Numeric strings from the API carry cosmetic precision ("1704.000000",
     * "45.0000"); normalise so formatting alone cannot flip the hash.
     */
    private static function scalar(mixed $value): mixed
    {
        if (is_bool($value) || $value === null) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return self::number((float) $value);
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && is_numeric($trimmed)) {
                return self::number((float) $trimmed);
            }
            return $trimmed;
        }
        return $value;
    }

    private static function number(float $number): string
    {
        if (abs($number - round($number)) < 0.000001) {
            return (string) (int) round($number);
        }
        return rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
    }
}
