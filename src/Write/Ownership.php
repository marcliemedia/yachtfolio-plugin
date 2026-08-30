<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Write;

use Otium\Yachtfolio\Mapping\AmenityText;
use Otium\Yachtfolio\Mapping\EquipmentMap;

/**
 * Who owns which field (plan §5.4) and — since the 2026-08-30 client mandate —
 * whether a field may be overwritten at all.
 *
 * Two orthogonal questions:
 *
 *  1. FIELD ownership. API-owned fields are candidates for a write; addon-only
 *     fields belong to the editors and the sync never touches them. Every
 *     `amenities_*` switcher that is not in the equipment map is addon-only too:
 *     the feed has no source for 31 of the 35 switchers, and writing 'false'
 *     into them would wipe live content.
 *
 *  2. POST origin. A yacht typed in by hand is protected: the sync may fill an
 *     EMPTY field but must never replace an existing value. A yacht created by
 *     the importer is feed-owned and is rewritten normally. This reverses the
 *     old D3 ("API is source of truth") for hand-entered yachts only.
 */
final class Ownership
{
    public const ADDON_ONLY = [
        'is_it_featured',
        'yf_visible',
        'charter_rates',
        'charter_rates_accordion',
        'specifications_deck_material',
        'specifications_electricity',
        'amenities_field',
        'toys_field',
    ];

    public const INFRA_PREFIX = 'yf_';

    /** Per-post provenance marker. */
    public const ORIGIN_META   = 'yf_origin';
    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_FEED   = 'feed';

    /**
     * Keys where a stored "0" carries no meaning and counts as empty, so the
     * feed is allowed to fill it. Everywhere else "0" is a real value.
     */
    public const ZERO_IS_BLANK = [
        'guests',
        'crews',
        'cabins',
        'length',
        'specifications_built',
        'specifications_crew',
    ];

    /**
     * Amenity switchers the feed is allowed to write, from both sources.
     *
     * EquipmentMap covers the vendor's structured `equipments` table (10 rows,
     * 4 with a counterpart here). AmenityText covers the free-text equipment
     * fields, which mention far more and are the only source a brochure-only
     * yacht has — those arrived with every switcher off.
     *
     * Single accessor on purpose: Writer, YachtMetabox and is_addon_only() must
     * agree on the set, or a field is writable in one place and rejected in
     * another.
     *
     * @return array<int,string>
     */
    public static function api_owned_amenity_keys(): array
    {
        return array_values(array_unique(array_merge(
            EquipmentMap::mapped_amenity_keys(),
            AmenityText::targets()
        )));
    }

    /** @param array<int,string> $mappedAmenityKeys */
    public static function is_addon_only(string $key, array $mappedAmenityKeys = []): bool
    {
        if (in_array($key, self::ADDON_ONLY, true)) {
            return true;
        }
        if (str_starts_with($key, 'amenities_')) {
            $mapped = $mappedAmenityKeys !== [] ? $mappedAmenityKeys : self::api_owned_amenity_keys();
            return !in_array($key, $mapped, true);
        }
        if (str_starts_with($key, 'rank_math')) {
            return true;
        }
        return str_starts_with($key, '_bak_');
    }

    /** @param array<int,string> $mappedAmenityKeys */
    public static function owner_of(string $key, array $mappedAmenityKeys = []): string
    {
        if (self::is_addon_only($key, $mappedAmenityKeys)) {
            return 'addon';
        }
        if (str_starts_with($key, self::INFRA_PREFIX)) {
            return 'infra';
        }
        return 'api';
    }

    /**
     * Provenance of a post.
     *
     * Fail-safe on purpose: a post that exists but carries no marker is treated
     * as MANUAL. The 19 yachts on this site were typed in by hand, and guessing
     * "feed" for an unknown post is the one mistake that destroys data.
     */
    public static function origin_of(?int $postId): string
    {
        if ($postId === null || $postId <= 0 || !get_post($postId)) {
            return self::ORIGIN_FEED; // a post that does not exist yet is ours to create
        }
        $stored = (string) get_post_meta($postId, self::ORIGIN_META, true);
        return $stored === self::ORIGIN_FEED ? self::ORIGIN_FEED : self::ORIGIN_MANUAL;
    }

    public static function is_manual(?int $postId): bool
    {
        return self::origin_of($postId) === self::ORIGIN_MANUAL;
    }

    /**
     * Is the currently stored value empty, i.e. may the feed fill it?
     *
     * $comparable is the normalised string form produced by Writer::comparable(),
     * so serialised repeaters and switchers arrive here already flattened.
     *
     * Deliberately NOT blank: a switcher holding 'false'. Somebody decided this
     * yacht does not have that amenity; the feed does not get to argue.
     */
    public static function is_blank(string $key, string $comparable): bool
    {
        if ($comparable === '') {
            return true;
        }
        if ($comparable === '[]' || $comparable === '{}' || $comparable === 'a:0:{}') {
            return true;
        }
        if (in_array($key, self::ZERO_IS_BLANK, true)) {
            $numeric = str_replace([' ', ','], ['', '.'], $comparable);
            if (is_numeric($numeric) && (float) $numeric === 0.0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Per-yacht escape hatch. Every entry is shown as a warning in the UI.
     *
     * @return array<int,string>
     */
    public static function locked_fields(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }
        $raw = (string) get_post_meta($postId, 'yf_locked_fields', true);
        if (trim($raw) === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $key = trim((string) $part);
            if ($key !== '') {
                $out[] = $key;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<int,string> $mappedAmenityKeys
     * @return array{0:array<string,mixed>,1:array<string,string>} allowed, rejected(key => reason)
     */
    public static function filter(array $meta, int $postId, array $mappedAmenityKeys = []): array
    {
        $locked = self::locked_fields($postId);
        $allowed = [];
        $rejected = [];

        foreach ($meta as $key => $value) {
            if (self::is_addon_only($key, $mappedAmenityKeys)) {
                $rejected[$key] = 'addon_only';
                continue;
            }
            if (in_array($key, $locked, true)) {
                $rejected[$key] = 'locked';
                continue;
            }
            $allowed[$key] = $value;
        }

        return [$allowed, $rejected];
    }
}
