<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Mapping;

/**
 * Declarative meta map: WP meta key => spec source + transform + owner.
 *
 * `owner` is the ownership rule from the plan: `api` fields are rewritten on
 * every sync, `addon` fields belong to the editors and are never touched.
 */
final class FieldMap
{
    public const OPTION = 'oy_yf_field_map';

    /** @return array<string,array{src:string|array<int,string>,tf:string,owner:string}> */
    public static function defaults(): array
    {
        return [
            // headline numbers
            'guests'   => ['src' => 'guests_sleeping', 'tf' => 'int',         'owner' => 'api'],
            'crews'    => ['src' => 'total_crew',      'tf' => 'int',         'owner' => 'api'],
            'cabins'   => ['src' => 'cabins',          'tf' => 'int',         'owner' => 'api'],
            'length'   => ['src' => 'length_metres',   'tf' => 'decimal:2',   'owner' => 'api'],

            // specification block (decimal comma, matching existing content)
            'specifications_loa'               => ['src' => 'length_metres',    'tf' => 'metric_m', 'owner' => 'api'],
            'specifications_beam'              => ['src' => 'beam_metres',      'tf' => 'metric_m', 'owner' => 'api'],
            'specifications_draft'             => ['src' => 'draft_metres',     'tf' => 'metric_m', 'owner' => 'api'],
            'specifications_built'             => ['src' => 'year_built',       'tf' => 'int',      'owner' => 'api'],
            'specifications_crew'              => ['src' => 'total_crew',       'tf' => 'int',      'owner' => 'api'],
            'specifications_hull_construction' => ['src' => 'hull_construction', 'tf' => 'text',    'owner' => 'api'],
            'specifications_cruising_speed'    => ['src' => 'cruising_speed',   'tf' => 'knots',    'owner' => 'api'],
            'specifications_maximum_speed'     => ['src' => 'max_speed',        'tf' => 'knots',    'owner' => 'api'],

            // editorial-only: the API carries no source for these two
            'specifications_deck_material' => ['src' => '', 'tf' => 'raw', 'owner' => 'addon'],
            'specifications_electricity'   => ['src' => '', 'tf' => 'raw', 'owner' => 'addon'],

            // display frames and editorial flags
            'charter_rates'           => ['src' => '', 'tf' => 'raw', 'owner' => 'addon'],
            'charter_rates_accordion' => ['src' => '', 'tf' => 'raw', 'owner' => 'addon'],
            'is_it_featured'          => ['src' => '', 'tf' => 'raw', 'owner' => 'addon'],

            // text
            'video-link' => ['src' => 'video.video_url', 'tf' => 'text', 'owner' => 'api'],

            // Tier B: data Yachtfolio carries that had nowhere to go before
            'yf_builder'                   => ['src' => 'builder',              'tf' => 'text', 'owner' => 'api'],
            'yf_previous_name'             => ['src' => 'previous_name',        'tf' => 'text', 'owner' => 'api'],
            'yf_flag'                      => ['src' => 'flag',                 'tf' => 'text', 'owner' => 'api'],
            'yf_gross_tons'                => ['src' => 'gross_tons',           'tf' => 'text', 'owner' => 'api'],
            'yf_registry_port'             => ['src' => 'registry_port',        'tf' => 'text', 'owner' => 'api'],
            'yf_year_refit'                => ['src' => 'year_refit',           'tf' => 'int',  'owner' => 'api'],
            'yf_classification'            => ['src' => 'classification',       'tf' => 'text', 'owner' => 'api'],
            'yf_interior_designer'          => ['src' => 'interior_designer',    'tf' => 'text', 'owner' => 'api'],
            'yf_naval_architect'           => ['src' => 'naval_architect',      'tf' => 'text', 'owner' => 'api'],
            'yf_hull_configuration'        => ['src' => 'hull_configuration',   'tf' => 'text', 'owner' => 'api'],
            'yf_superstructure'            => ['src' => 'superstructure',       'tf' => 'text', 'owner' => 'api'],
            'yf_rig'                       => ['src' => 'rig',                  'tf' => 'text', 'owner' => 'api'],
            'yf_sail_power'                => ['src' => 'sail_power',            'tf' => 'sentence', 'owner' => 'api'],
            'yf_listing_type'              => ['src' => 'listing_type',          'tf' => 'sentence', 'owner' => 'api'],
            'yf_commercial_status'         => ['src' => 'commercial_status',      'tf' => 'text', 'owner' => 'api'],
            'yf_summer_base_port'          => ['src' => 'summer_base_port',      'tf' => 'text', 'owner' => 'api'],
            'yf_winter_base_port'          => ['src' => 'winter_base_port',      'tf' => 'text', 'owner' => 'api'],
            'yf_summer_port_location'      => ['src' => 'summer_port_location',  'tf' => 'text', 'owner' => 'api'],
            'yf_winter_port_location'      => ['src' => 'winter_port_location',  'tf' => 'text', 'owner' => 'api'],
            'yf_range'                     => ['src' => 'range',                 'tf' => 'text', 'owner' => 'api'],
            'yf_fuel_consumption_cruising' => ['src' => 'fuel_consumption_cruising', 'tf' => 'text', 'owner' => 'api'],
            'yf_fuel_consumption_max'      => ['src' => 'fuel_consumption_max',      'tf' => 'text', 'owner' => 'api'],
            'yf_fuel_consumption_eco'      => ['src' => 'fuel_consumption_eco',      'tf' => 'text', 'owner' => 'api'],
            'yf_fuel_consumption_units'    => ['src' => 'fuel_consumption_units',    'tf' => 'text', 'owner' => 'api'],
            'yf_fuel_consumption_comments' => ['src' => 'fuel_consumption_comments', 'tf' => 'text', 'owner' => 'api'],
            'yf_guests_cruising'           => ['src' => 'guests_cruising',       'tf' => 'int',  'owner' => 'api'],
            'yf_guests_accommodation'      => ['src' => 'guests_accommodation',  'tf' => 'text', 'owner' => 'api'],
            'yf_cabins_single'             => ['src' => 'single_cabins',         'tf' => 'int',  'owner' => 'api'],
            'yf_cabins_twin'               => ['src' => 'twin_cabins',           'tf' => 'int',  'owner' => 'api'],
            'yf_cabins_double'             => ['src' => 'double_cabins',         'tf' => 'int',  'owner' => 'api'],
            'yf_cabins_triple'             => ['src' => 'triple_cabins',         'tf' => 'int',  'owner' => 'api'],
            'yf_cabins_convertible'        => ['src' => 'convertible_cabins',    'tf' => 'int',  'owner' => 'api'],
            'yf_master_cabin_main_deck'    => ['src' => 'master_cabin_on_main_deck', 'tf' => 'text', 'owner' => 'api'],
            'yf_beds_total'                => ['src' => 'beds',                  'tf' => 'int',  'owner' => 'api'],
            'yf_beds_king'                 => ['src' => 'king_beds',             'tf' => 'int',  'owner' => 'api'],
            'yf_beds_queen'                => ['src' => 'queen_beds',            'tf' => 'int',  'owner' => 'api'],
            'yf_beds_double'               => ['src' => 'double_beds',           'tf' => 'int',  'owner' => 'api'],
            'yf_beds_single'               => ['src' => 'single_beds',           'tf' => 'int',  'owner' => 'api'],
            'yf_beds_pullman'              => ['src' => 'pullman_beds',          'tf' => 'int',  'owner' => 'api'],
            'yf_beds_bulk'                 => ['src' => 'bunk_beds',             'tf' => 'int',  'owner' => 'api'],
            'yf_crew_profiles'             => ['src' => 'crew_profiles',         'tf' => 'text', 'owner' => 'api'],
            'yf_av_facilities'             => ['src' => 'av_facilities',         'tf' => 'text', 'owner' => 'api'],
            'yf_communications'            => ['src' => 'communications',        'tf' => 'text', 'owner' => 'api'],
            'yf_rate_det'                  => ['src' => 'rate_det',              'tf' => 'text', 'owner' => 'api'],
            'yf_tax_comments'              => ['src' => 'tax_comments',          'tf' => 'text', 'owner' => 'api'],
            'yf_special_conditions'        => ['src' => 'special_conditions',    'tf' => 'text', 'owner' => 'api'],
            'yf_contracts'                 => ['src' => 'contracts',             'tf' => 'text', 'owner' => 'api'],
            'yf_refit_det'                 => ['src' => 'refit_det',             'tf' => 'text', 'owner' => 'api'],
            'yf_notes'                     => ['src' => 'notes',                 'tf' => 'text', 'owner' => 'api'],
            'yf_location_det'              => ['src' => 'location_det',          'tf' => 'text', 'owner' => 'api'],
            // A 0/1 flag: "Yes" or nothing, so the row hides itself instead of printing "1".
            'yf_myba_tipping_policy'       => ['src' => 'myba_tipping_policy',   'tf' => 'yes_empty', 'owner' => 'api'],

            /**
             * Fields the API carries that nothing stored before, found by the
             * 2026-08-30 three-way coverage audit (API -> DB -> template).
             *
             * The imperial dimensions matter because they are the vendor's own
             * exact strings ("160' 9 1/8"); deriving them from the metric value
             * loses the fractional inches. yf_equipment_list is the brochure's
             * rich equipment sentence, far longer than the four mapped amenity
             * switchers it partially overlaps.
             */
            'yf_length_imperial' => ['src' => ['length_imperial', 'length_feet'], 'tf' => 'text', 'owner' => 'api'],
            'yf_beam_imperial'   => ['src' => ['beam_imperial', 'beam_feet'],     'tf' => 'text', 'owner' => 'api'],
            'yf_draft_imperial'  => ['src' => ['draft_imperial', 'draft_feet'],   'tf' => 'text', 'owner' => 'api'],
            'yf_equipment_list'  => ['src' => 'equipment',                        'tf' => 'text', 'owner' => 'api'],
        ];
    }

    /** @return array<string,array{src:string|array<int,string>,tf:string,owner:string}> */
    public static function stored(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /** @return array<string,array{src:string|array<int,string>,tf:string,owner:string}> */
    public static function effective(): array
    {
        $map = self::defaults();
        foreach (self::stored() as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $map[$key] = [
                'src'   => $entry['src'] ?? ($map[$key]['src'] ?? ''),
                'tf'    => (string) ($entry['tf'] ?? ($map[$key]['tf'] ?? 'raw')),
                'owner' => (string) ($entry['owner'] ?? ($map[$key]['owner'] ?? 'api')),
            ];
        }
        return $map;
    }

    /** @param array<string,array<string,mixed>> $map */
    public static function save(array $map): void
    {
        $clean = [];
        foreach ($map as $metaKey => $entry) {
            $metaKey = sanitize_key((string) $metaKey);
            if ($metaKey === '' || !is_array($entry)) {
                continue;
            }
            $src = $entry['src'] ?? '';
            $clean[$metaKey] = [
                'src'   => is_array($src) ? array_values(array_map('strval', $src)) : (string) $src,
                'tf'    => (string) ($entry['tf'] ?? 'raw'),
                'owner' => ($entry['owner'] ?? 'api') === 'addon' ? 'addon' : 'api',
            ];
        }
        update_option(self::OPTION, $clean, false);
    }

    public static function reset(): void
    {
        delete_option(self::OPTION);
    }

    /** @return array<int,string> meta keys the feed owns */
    public static function api_owned_keys(): array
    {
        $out = [];
        foreach (self::effective() as $key => $entry) {
            if (($entry['owner'] ?? 'api') === 'api') {
                $out[] = $key;
            }
        }
        return $out;
    }
}
