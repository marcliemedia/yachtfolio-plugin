<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Write;

/**
 * Storage formats used by the existing content, reproduced exactly.
 *
 * Verified on the live database: switchers hold the strings 'true'/'false',
 * JetEngine repeaters are PHP-serialised maps keyed item-0/item-1/…, and the
 * `gallery` field is a comma-separated attachment-ID list with NO trailing
 * comma (26/26 rows, checked with a REGEXP query).
 */
final class MetaFormat
{
    public static function switcher(bool $on): string
    {
        return $on ? 'true' : 'false';
    }

    public static function is_switcher_on(mixed $stored): bool
    {
        if (is_bool($stored)) {
            return $stored;
        }
        return in_array(strtolower(trim((string) $stored)), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,string>>
     */
    public static function repeater(array $rows): array
    {
        $out = [];
        $index = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }
            $clean = [];
            foreach ($row as $field => $value) {
                $clean[(string) $field] = is_scalar($value) || $value === null ? (string) $value : wp_json_encode($value);
            }
            $out['item-' . $index] = $clean;
            $index++;
        }
        return $out;
    }

    /** @return array<int,array<string,string>> */
    public static function read_repeater(mixed $stored): array
    {
        if (is_string($stored)) {
            $stored = maybe_unserialize($stored);
            if (is_string($stored) && $stored !== '') {
                $decoded = json_decode($stored, true);
                $stored = is_array($decoded) ? $decoded : [];
            }
        }
        if (!is_array($stored)) {
            return [];
        }

        $rows = [];
        foreach ($stored as $row) {
            if (is_array($row)) {
                $clean = [];
                foreach ($row as $field => $value) {
                    $clean[(string) $field] = is_scalar($value) || $value === null ? (string) $value : '';
                }
                $rows[] = $clean;
            }
        }
        return $rows;
    }

    /**
     * @param array<int,int> $ids
     */
    public static function gallery(array $ids): string
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        return implode(',', $clean);
    }

    /** @return array<int,int> tolerates a legacy trailing comma */
    public static function read_gallery(mixed $stored): array
    {
        if (is_array($stored)) {
            $parts = $stored;
        } else {
            $parts = explode(',', (string) $stored);
        }

        $out = [];
        foreach ($parts as $part) {
            $id = (int) trim((string) $part);
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    public static function decimal_comma(float|string $value, int $decimals = 2): string
    {
        if (is_string($value)) {
            $value = (float) str_replace(',', '.', $value);
        }
        return number_format($value, $decimals, ',', '');
    }

    public static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return self::switcher($value);
        }
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return (string) wp_json_encode($value);
    }

    /**
     * Decides how a mapped value must be stored.
     *
     * @return array{0:string,1:mixed} shape (switcher|repeater|scalar) and the storable value
     */
    public static function for_value(mixed $value): array
    {
        if (is_bool($value)) {
            return ['switcher', self::switcher($value)];
        }
        if (is_array($value) && array_is_list($value) && $value !== [] && is_array($value[0])) {
            return ['repeater', self::repeater($value)];
        }
        if (is_array($value)) {
            return ['scalar', self::scalar($value)];
        }
        return ['scalar', self::scalar($value)];
    }
}
