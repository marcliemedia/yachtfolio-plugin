<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Mapping;

/**
 * Value transforms. Output formats mirror what the site already stores, e.g.
 * `specifications_loa` uses a decimal comma ("49,00m") while `length` is a
 * plain number ("49.00").
 */
final class Transforms
{
    /** @var array<int,string> */
    private static array $errors = [];

    private const CURRENCY = [
        'EUR' => ['symbol' => '€',    'position' => 'suffix', 'thousands' => '.'],
        'USD' => ['symbol' => '$',    'position' => 'prefix', 'thousands' => ','],
        'GBP' => ['symbol' => '£',    'position' => 'prefix', 'thousands' => ','],
        'CAD' => ['symbol' => 'CA$',  'position' => 'prefix', 'thousands' => ','],
        'AUD' => ['symbol' => 'A$',   'position' => 'prefix', 'thousands' => ','],
        'NZD' => ['symbol' => 'NZ$',  'position' => 'prefix', 'thousands' => ','],
    ];

    /** @param array<string,mixed> $ctx */
    public static function apply(string $spec, mixed $value, array $ctx = []): mixed
    {
        [$name, $arg] = array_pad(explode(':', $spec, 2), 2, null);

        return match ($name) {
            '', 'raw'          => $value,
            'text'             => self::text($value),
            'int'              => self::int($value),
            'float'            => self::float($value),
            'decimal'          => self::decimal($value, (int) ($arg ?? 2)),
            'decimal_comma'    => self::decimal_comma($value, (int) ($arg ?? 2)),
            'metric_m'         => self::metric_m($value),
            'knots'            => self::knots($value),
            'title_case'       => self::title_case((string) self::text($value)),
            'sentence'         => self::sentence((string) self::text($value)),
            'slug'             => self::slug((string) self::text($value)),
            'paragraph_first'  => self::paragraph_first((string) self::text($value)),
            'yes_empty'        => self::yes_empty($value),
            'bullets_to_lines' => self::bullets_to_lines($value),
            default            => self::unknown($name, $value),
        };
    }

    public static function text(mixed $value): string
    {
        if (is_array($value) || $value === null) {
            return '';
        }
        return trim(wp_strip_all_tags((string) $value));
    }

    public static function int(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        $clean = preg_replace('/[^0-9\-]/', '', (string) $value);
        return $clean === '' || $clean === null ? null : (int) $clean;
    }

    public static function float(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        $clean = str_replace(',', '.', trim((string) $value));
        return is_numeric($clean) ? (float) $clean : null;
    }

    public static function decimal(mixed $value, int $decimals = 2): string
    {
        $number = self::float($value);
        return $number === null ? '' : number_format($number, $decimals, '.', '');
    }

    public static function decimal_comma(mixed $value, int $decimals = 2): string
    {
        $number = self::float($value);
        return $number === null ? '' : number_format($number, $decimals, ',', '');
    }

    /** 43.28 => "43,28m" */
    public static function metric_m(mixed $value): string
    {
        $formatted = self::decimal_comma($value, 2);
        return $formatted === '' ? '' : $formatted . 'm';
    }

    /** 11 => "11 knots" */
    public static function knots(mixed $value): string
    {
        $number = self::float($value);
        if ($number === null || $number <= 0) {
            return '';
        }
        $formatted = abs($number - round($number)) < 0.01
            ? (string) (int) round($number)
            : rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.');
        return $formatted . ' knots';
    }

    /**
     * "sail" => "Sail". First letter only, the rest left alone.
     *
     * Some feed enumerations arrive entirely lower case ("sail", "power",
     * "public"), which reads as a typo next to the editorial fields beside it.
     * title_case() cannot help: it deliberately skips anything that is not
     * already all upper case, so proper nouns survive untouched.
     */
    public static function sentence(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
    }

    /** "ACAPELLA" => "Acapella", "ANNABEL II" => "Annabel II" */
    public static function title_case(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($value === '') {
            return '';
        }
        // Mixed-case input is already editorial: leave it alone.
        if ($value !== mb_strtoupper($value, 'UTF-8')) {
            return $value;
        }

        $words = explode(' ', $value);
        foreach ($words as $i => $word) {
            if ($word === '') {
                continue;
            }
            if (preg_match('/^[IVXLC]+$/', $word) === 1 || mb_strlen($word, 'UTF-8') <= 2) {
                continue; // roman numerals and short tokens (II, IV, DE handled below)
            }
            $words[$i] = mb_convert_case(mb_strtolower($word, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }

        return implode(' ', $words);
    }

    public static function slug(string $value): string
    {
        return sanitize_title($value);
    }

    public static function paragraph_first(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $parts = preg_split('/\R{2,}/', $value) ?: [$value];
        $first = trim((string) $parts[0]);
        if (mb_strlen($first, 'UTF-8') <= 300) {
            return $first;
        }
        $cut = mb_substr($first, 0, 300, 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
        return rtrim($space !== false ? mb_substr($cut, 0, $space, 'UTF-8') : $cut, " ,.;:") . '…';
    }

    public static function yes_empty(mixed $value): string
    {
        if (is_string($value)) {
            $value = !in_array(strtolower(trim($value)), ['', '0', 'false', 'no'], true);
        }
        return $value ? 'Yes' : '';
    }

    /** @return array<int,string> */
    public static function bullets_to_lines(mixed $value): array
    {
        if (is_array($value)) {
            $lines = $value;
        } else {
            $lines = preg_split('/\R/', (string) $value) ?: [];
        }

        $out = [];
        foreach ($lines as $line) {
            $clean = trim(preg_replace('/^[\s•\-\*\x{00B7}\x{2022}]+/u', '', (string) $line) ?? '');
            $clean = trim(wp_strip_all_tags($clean));
            if ($clean !== '') {
                $out[] = $clean;
            }
        }
        return $out;
    }

    /**
     * Money with the row's own currency. Null or non-positive renders empty —
     * never "0 €".
     */
    public static function money(?float $amount, string $currency, string $locale = 'hr'): string
    {
        if ($amount === null || $amount <= 0) {
            return '';
        }

        $code = strtoupper(trim($currency));
        $format = self::CURRENCY[$code] ?? null;

        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;

        if ($format === null) {
            // Unknown code: keep the number readable and label it explicitly.
            return number_format($amount, $decimals, ',', '.') . ' ' . ($code !== '' ? $code : '');
        }

        $number = number_format(
            $amount,
            $decimals,
            $format['thousands'] === '.' ? ',' : '.',
            $format['thousands']
        );

        return $format['position'] === 'prefix'
            ? $format['symbol'] . $number
            : $number . ' ' . $format['symbol'];
    }

    /** @return array<int,string> */
    public static function last_errors(): array
    {
        return self::$errors;
    }

    public static function reset_errors(): void
    {
        self::$errors = [];
    }

    private static function unknown(string $name, mixed $value): mixed
    {
        self::$errors[] = $name;
        return $value;
    }
}
