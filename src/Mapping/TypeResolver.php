<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Mapping;

/**
 * `yacht-type` term from the feed (decision D5).
 *
 * `sail_power` is present for every yacht, so power/sail always resolves. The
 * site also uses Catamaran, Gulet and Mini cruiser, which the feed carries no
 * discriminator for — those stay untouched and the yacht is flagged instead of
 * guessed.
 */
final class TypeResolver
{
    public const OPTION = 'oy_yf_type_alias';

    /** @return array<string,string> rule value (lowercased) => term name */
    public static function defaults(): array
    {
        return [
            'power' => 'Motor yacht',
            'sail'  => 'Motor sailer',
        ];
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
                $out[strtolower(trim($from))] = trim($to);
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
            $from = strtolower(trim((string) $from));
            $to = trim(sanitize_text_field((string) $to));
            if ($from !== '' && $to !== '') {
                $clean[$from] = $to;
            }
        }
        update_option(self::OPTION, $clean, false);
    }

    public static function reset(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * @param array<string,mixed> $spec
     * @return array{term:?string,derivable:bool,matched:string}
     */
    public static function resolve(array $spec): array
    {
        $aliases = self::effective();

        // Most specific first: a superstructure/hull/rig alias overrides the
        // power/sail default when the operator configured one.
        foreach (['superstructure', 'hull_configuration', 'rig'] as $field) {
            $value = strtolower(trim((string) ($spec[$field] ?? '')));
            if ($value !== '' && isset($aliases[$value])) {
                return ['term' => $aliases[$value], 'derivable' => true, 'matched' => "$field=$value"];
            }
        }

        $sailPower = strtolower(trim((string) ($spec['sail_power'] ?? '')));
        if ($sailPower !== '' && isset($aliases[$sailPower])) {
            return ['term' => $aliases[$sailPower], 'derivable' => true, 'matched' => "sail_power=$sailPower"];
        }

        return ['term' => null, 'derivable' => false, 'matched' => ''];
    }
}
