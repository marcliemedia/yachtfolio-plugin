<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Mapping;

/**
 * Operating-area name => `yacht-destination` term name.
 *
 * The feed carries 107 areas in 26 groups while the site has 50 destination
 * terms, so aliases exist to land a feed value on an existing marketing term
 * instead of creating a near-duplicate.
 */
final class AreaAlias
{
    public const OPTION = 'oy_yf_area_alias';

    /** @return array<string,string> */
    public static function defaults(): array
    {
        return [];
    }

    /** @return array<string,string> */
    public static function stored(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return [];
        }
        $out = [];
        foreach ($stored as $from => $to) {
            if (is_string($from) && is_string($to) && trim($from) !== '' && trim($to) !== '') {
                $out[self::key($from)] = trim($to);
            }
        }
        return $out;
    }

    /** @return array<string,string> */
    public static function effective(): array
    {
        return self::stored() + self::defaults();
    }

    /** @param array<string,string> $map */
    public static function save(array $map): void
    {
        $clean = [];
        foreach ($map as $from => $to) {
            $from = trim((string) $from);
            $to = trim(sanitize_text_field((string) $to));
            if ($from !== '' && $to !== '') {
                $clean[self::key($from)] = $to;
            }
        }
        update_option(self::OPTION, $clean, false);
    }

    public static function reset(): void
    {
        delete_option(self::OPTION);
    }

    /** Identity when unmapped: the API is authoritative for the value itself. */
    public static function resolve(string $areaName): string
    {
        $areaName = trim($areaName);
        if ($areaName === '') {
            return '';
        }
        return self::effective()[self::key($areaName)] ?? $areaName;
    }

    public static function is_mapped(string $areaName): bool
    {
        return isset(self::effective()[self::key($areaName)]);
    }

    /**
     * @param array<int,string> $areaNames
     * @return array<int,string> resolved, deduped, order preserved
     */
    public static function resolve_all(array $areaNames): array
    {
        $out = [];
        foreach ($areaNames as $name) {
            $term = self::resolve((string) $name);
            if ($term !== '' && !in_array($term, $out, true)) {
                $out[] = $term;
            }
        }
        return $out;
    }

    private static function key(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
    }
}
