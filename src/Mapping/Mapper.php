<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Mapping;

use Otium\Yachtfolio\Domain\YachtPayload;
use Otium\Yachtfolio\Reference\AreaResolver;
use Otium\Yachtfolio\Reference\ReferenceCache;
use Otium\Yachtfolio\Reference\SeasonResolver;
use Otium\Yachtfolio\Support\Settings;

/**
 * Payload -> WP fields. Produces raw values only; nothing is written here.
 */
final class Mapper
{
    public const FLAG_TYPE_UNDERIVABLE   = 'type_underivable';
    public const FLAG_UNMAPPED_EQUIPMENT = 'unmapped_equipment';
    public const FLAG_UNMAPPED_AREA      = 'unmapped_area';
    public const FLAG_NO_PRICES          = 'no_prices';
    public const FLAG_NO_DESCRIPTION     = 'no_description';

    public function __construct(
        private Settings $settings,
        private ReferenceCache $reference,
        private SeasonResolver $seasons,
        private AreaResolver $areas
    ) {
    }

    public function map(YachtPayload $p): MapResult
    {
        $report = new MappingReport();
        $meta = [];
        $flags = [];

        /* ---------- declarative field map ---------- */
        foreach (FieldMap::effective() as $metaKey => $entry) {
            if (($entry['owner'] ?? 'api') !== 'api') {
                continue; // editor-owned: never produced by the mapper
            }
            $src = $entry['src'] ?? '';
            if ($src === '' || $src === []) {
                continue;
            }
            $value = $this->source_value($p, $src);
            if ($value === null || $value === '') {
                continue; // no source -> leave whatever the editors have
            }
            $out = Transforms::apply((string) ($entry['tf'] ?? 'raw'), $value, ['payload' => $p]);
            if ($out === null || $out === '' || $out === []) {
                continue;
            }
            $meta[$metaKey] = $out;
        }

        /* ---------- composed specification fields ---------- */
        $sailPower = strtolower($p->spec_string('sail_power'));
        $maxSpeed = $p->spec('max_speed');
        if ($sailPower === 'sail' && $maxSpeed !== null) {
            $meta['specifications_maximum_sailing_speed'] = Transforms::knots($maxSpeed);
        }

        [$engines, $generators] = $this->split_engines($p->spec_string('engines_generators'));
        if ($engines !== '') {
            $meta['specifications_engines'] = $engines;
            if ($generators !== null && $generators !== '') {
                $meta['specifications_generators'] = $generators;
            }
        }

        $cabinConfig = $this->cabin_configuration($p);
        if ($cabinConfig !== '') {
            $meta['cabin_configuration'] = $cabinConfig;
        }

        /* ---------- description ---------- */
        if ($p->description !== '') {
            $meta['short_description'] = Transforms::paragraph_first($p->description);
        } else {
            $flags[] = self::FLAG_NO_DESCRIPTION;
        }

        /* ---------- key features repeater ---------- */
        if ($p->key_features !== []) {
            $rows = [];
            foreach ($p->key_features as $feature) {
                [$title, $description] = $this->split_feature($feature);
                $rows[] = ['key_features_title' => $title, 'key_features_description' => $description];
            }
            $meta['key_features'] = $rows;
        }

        /* ---------- toys repeater ---------- */
        $toys = Transforms::bullets_to_lines($p->spec('toys', ''));
        if ($toys !== []) {
            $meta['toys_repeater'] = array_map(static fn(string $t): array => ['toys_title' => $t], $toys);
        }

        /* ---------- equipment switchers (4 of 35 have an API source) ---------- */
        if ($p->owned) {
            foreach (EquipmentMap::switches($p->equipment) as $key => $on) {
                $meta[$key] = $on;
            }
            foreach (EquipmentMap::unmapped($p->equipment) as $equipmentId) {
                $name = $this->reference->equipment_names()[$equipmentId] ?? ('id ' . $equipmentId);
                $report->add(MappingReport::KIND_EQUIPMENT, $name . " (id $equipmentId)", $p->yf_id);
                $flags[] = self::FLAG_UNMAPPED_EQUIPMENT;
            }
            if (in_array(1, $p->equipment, true)) {
                $meta['specifications_air_condition'] = 'Yes';
            }
        }

        /* ---------- amenity switchers proven by the free-text fields ---------- */
        /**
         * Deliberately outside the $p->owned branch above.
         *
         * The structured `equipments` table only comes with the detail record,
         * so a brochure-only yacht reached the page with every one of the 37
         * switchers off — while its own brochure text read "Watermakers, Air
         * conditioning, Starlink, WiFi connection". That text is what every
         * yacht has.
         *
         * Only ever sets true: absence of a phrase is not evidence of absence,
         * and writing false would erase editor-owned content.
         */
        $amenityTexts = [];
        foreach (AmenityText::SOURCE_FIELDS as $field) {
            $amenityTexts[] = (string) $p->spec($field, '');
        }
        foreach (AmenityText::detect($amenityTexts) as $key => $evidence) {
            if (($meta[$key] ?? null) === true || ($meta[$key] ?? null) === 'true') {
                continue; // the structured table already proved it
            }
            $meta[$key] = 'true';
        }

        /* ---------- prices ---------- */
        $display = RatesTable::display($p->rates, $this->seasons, $this->settings);
        if ($p->rates === []) {
            $flags[] = self::FLAG_NO_PRICES;
        } else {
            // Rates are feed-owned end to end, so an empty amount is a
            // deliberate "the feed has no price" and does overwrite.
            foreach (['high', 'low', 'shoulder'] as $period) {
                $meta["{$period}_season_rate"] = $display[$period]['rate'];
            }

            // High and low are two amounts of the SAME season, so they share one
            // date window. Writing that window into both fields would replace a
            // meaningful editorial split ("Jun 22nd – Aug 27th" vs "all other
            // dates") with two identical strings, so only the high period gets
            // it; the low window stays editor-owned unless its season differs.
            $meta['high_season_time'] = $display['high']['time'];
            if ($display['low']['season_id'] !== $display['high']['season_id']) {
                $meta['low_season_time'] = $display['low']['time'];
            }
            if ($display['shoulder']['season_id'] > 0
                && $display['shoulder']['season_id'] !== $display['high']['season_id']) {
                $meta['shoulder_season_time'] = $display['shoulder']['time'];
            }

            $notes = $this->price_notes($p);
            if ($notes !== null) {
                foreach (['high', 'low', 'shoulder'] as $period) {
                    $meta["{$period}_season_price_notes"] = $notes;
                }
            }
            $meta['yf_rates'] = RatesTable::repeater($p->rates, $this->seasons);
        }

        /* ---------- crew / licences / requests ---------- */
        if ($p->crew !== []) {
            $meta['yf_crew'] = array_map(static function (array $member): array {
                return [
                    'first_name'  => (string) $member['first_name'],
                    'last_name'   => (string) $member['last_name'],
                    'position'    => (string) $member['position'],
                    'nationality' => (string) $member['nationality'],
                    'description' => (string) $member['description'],
                    'tba'         => !empty($member['tba']) ? 'true' : 'false',
                ];
            }, $p->crew);
        }

        if ($p->licences !== []) {
            $licenceNames = $this->reference->licence_names();
            $statusNames = $this->reference->licence_status_names();
            $meta['yf_licences'] = array_map(function (array $row) use ($licenceNames, $statusNames): array {
                return [
                    'licence' => $licenceNames[$row['licence_id']] ?? ('licence ' . $row['licence_id']),
                    'status'  => $statusNames[$row['status_id']] ?? '',
                    'season'  => $this->seasons->label($row['season_id']),
                ];
            }, $p->licences);
        }

        if ($p->special_requests !== []) {
            $requestNames = $this->reference->special_request_names();
            $rows = [];
            foreach ($p->special_requests as $seasonId => $ids) {
                $names = [];
                foreach ($ids as $id) {
                    $names[] = $requestNames[$id] ?? ('request ' . $id);
                }
                $rows[] = ['season' => $this->seasons->label((int) $seasonId), 'requests' => implode(', ', $names)];
            }
            $meta['yf_special_requests'] = $rows;
        }

        if ($p->seasons_unavailable !== []) {
            $labels = array_map(fn(int $id): string => $this->seasons->label($id), $p->seasons_unavailable);
            $meta['yf_seasons_unavailable'] = implode(', ', $labels);
        }

        /* ---------- broker (internal use) ---------- */
        foreach (['company_name' => 'yf_broker_company', 'name' => 'yf_broker_name', 'email' => 'yf_broker_email', 'phone' => 'yf_broker_phone'] as $src => $metaKey) {
            if (!empty($p->broker[$src])) {
                $meta[$metaKey] = $p->broker[$src];
            }
        }

        /* ---------- video ---------- */
        if (!empty($p->video['video_url'])) {
            $meta['video-link'] = (string) $p->video['video_url'];
        }

        /* ---------- areas / taxonomy ---------- */
        $currentSeason = $this->seasons->current();
        $currentSeasonId = $currentSeason !== null ? (int) $currentSeason['id'] : 0;
        $areaIds = $p->areas_by_season[$currentSeasonId] ?? [];
        if ($areaIds === [] && $p->areas_by_season !== []) {
            $areaIds = (array) reset($p->areas_by_season);
        }

        $areaNames = $this->areas->names(array_map('intval', $areaIds));
        foreach ($areaNames as $name) {
            if (!AreaAlias::is_mapped($name)) {
                $report->add(MappingReport::KIND_AREA, $name, $p->yf_id);
            }
        }
        $destinationTerms = AreaAlias::resolve_all($areaNames);
        if ($areaNames !== [] && $destinationTerms === []) {
            $flags[] = self::FLAG_UNMAPPED_AREA;
        }
        if ($destinationTerms !== []) {
            $meta['specifications_cruising_area'] = implode(', ', $destinationTerms);
        }

        $terms = [];
        if ($destinationTerms !== []) {
            $terms['yacht-destination'] = $destinationTerms;
        }

        $cabins = $p->spec_int('cabins');
        if ($cabins !== null && $cabins > 0) {
            $terms['yacht-cabins'] = [$cabins === 1 ? '1 cabin' : "$cabins cabins"];
        }

        $guests = $p->spec_int('guests_sleeping');
        if ($guests !== null && $guests > 0) {
            $terms['yacht-guests'] = [$guests === 1 ? '1 guest' : "$guests guests"];
        }

        $type = TypeResolver::resolve($p->spec);
        if ($type['term'] !== null) {
            $terms['yacht-type'] = [$type['term']];
        } else {
            $flags[] = self::FLAG_TYPE_UNDERIVABLE;
            $report->add(MappingReport::KIND_TYPE, $p->spec_string('sail_power', '(empty sail_power)'), $p->yf_id);
        }
        $meta['yf_type_derivable'] = $type['derivable'];

        /* ---------- post fields ---------- */
        $post = [
            'post_title'   => Transforms::title_case($p->name),
            'post_content' => $p->description,
            'post_name'    => sanitize_title($p->name),
        ];

        return new MapResult($post, $meta, $terms, $report, array_values(array_unique($flags)));
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param string|array<int,string> $src supports dotted paths (video.video_url)
     */
    private function source_value(YachtPayload $p, string|array $src): mixed
    {
        if (is_array($src)) {
            foreach ($src as $candidate) {
                $value = $this->source_value($p, (string) $candidate);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
            return null;
        }

        if (str_contains($src, '.')) {
            [$root, $leaf] = explode('.', $src, 2);
            $bag = match ($root) {
                'video'  => $p->video,
                'broker' => $p->broker,
                'spec'   => $p->spec,
                default  => [],
            };
            return $bag[$leaf] ?? null;
        }

        return $p->spec($src);
    }

    /**
     * Splits the combined engines/generators text.
     *
     * Real feed value (ACAPELLA): "2 x 600 John Deere (heavy-duty)\n2 x 100 kW
     * Generators\nElectricity: 24 V…" — the marker is the word "Generators"
     * mid-line, not a "Generators:" label, so the boundary is the start of the
     * segment that mentions a generator.
     *
     * @return array{0:string,1:?string} engines, generators (null = no split, leave the field alone)
     */
    private function split_engines(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['', null];
        }

        $strip = static fn(string $s): string => trim((string) preg_replace('/^(engines?|generators?)\s*:\s*/iu', '', trim($s)));

        // Prefer a line boundary; fall back to a sentence/segment boundary.
        $lines = preg_split('/\R/u', $text) ?: [];
        if (count($lines) > 1) {
            foreach ($lines as $index => $line) {
                if ($index > 0 && preg_match('/generator/iu', $line) === 1) {
                    $engines = $strip(implode("\n", array_slice($lines, 0, $index)));
                    $generators = $strip(implode("\n", array_slice($lines, $index)));
                    return [$engines, $generators];
                }
            }
        }

        if (preg_match('/^(.*?)((?:\d+\s*[xX]\s*)?[^.;\n]*generator.*)$/isu', $text, $m) === 1 && trim($m[1]) !== '') {
            return [$strip($m[1]), $strip($m[2])];
        }

        // No marker: everything is engine text and the generators field keeps
        // whatever the editors put there. Guessing would invent data.
        return [$strip($text), null];
    }

    private function cabin_configuration(YachtPayload $p): string
    {
        $parts = [];
        foreach ([
            'double_cabins'      => 'Double',
            'twin_cabins'        => 'Twin',
            'triple_cabins'      => 'Triple',
            'single_cabins'      => 'Single',
            'convertible_cabins' => 'Convertible',
        ] as $key => $label) {
            $count = $p->spec_int($key);
            if ($count !== null && $count > 0) {
                $parts[] = "$count $label";
            }
        }
        return implode(', ', $parts);
    }

    /** @return array{0:string,1:string} title, description */
    private function split_feature(string $feature): array
    {
        $position = mb_strpos($feature, ':');
        if ($position !== false && $position > 0 && $position < mb_strlen($feature) - 1) {
            return [
                trim(mb_substr($feature, 0, $position + 1)),
                trim(mb_substr($feature, $position + 1)),
            ];
        }
        return [trim($feature), ''];
    }

    private function price_notes(YachtPayload $p): ?string
    {
        $parts = [];
        foreach (['rate_det', 'tax_comments'] as $key) {
            $value = trim((string) $p->spec($key, ''));
            if ($value !== '') {
                $parts[] = wp_strip_all_tags($value);
            }
        }
        // Only owned yachts carry these; for the rest return null so existing
        // editorial notes survive.
        return $parts === [] ? ($p->owned ? '' : null) : implode(' ', $parts);
    }
}
