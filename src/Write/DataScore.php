<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Write;

/**
 * How much of a yacht is actually populated, in one place.
 *
 * The importer already records a presence marker per section on the post; this
 * only reads them. The definition lives here rather than in the admin table so
 * the meter, the sort column and the "incomplete" filter can never drift apart.
 *
 * The count is mirrored into `oy_yf_map.data_score` on every write, because a
 * value stored in post meta cannot be sorted or filtered in SQL. Post meta stays
 * the source of truth; the column is an index of it.
 */
final class DataScore
{
    /**
     * The sections a charter page is judged complete by. Deliberately not every
     * marker the writer sets: licences, special requests and unavailable seasons
     * are absent on plenty of perfectly complete yachts, so counting them would
     * report healthy yachts as incomplete.
     *
     * @var array<string,string> meta key => untranslated label
     */
    private const SECTIONS = [
        'yf_has_rates'     => 'Rates',
        'yf_has_amenities' => 'Amenities',
        'yf_has_crew'      => 'Crew',
        'yf_has_toys'      => 'Toys',
        'yf_has_gallery'   => 'Gallery',
    ];

    public const MAX = 5;

    /** @return array<string,string> meta key => translated label */
    public static function sections(): array
    {
        return [
            'yf_has_rates'     => __('Rates', 'otium-yachtfolio-sync'),
            'yf_has_amenities' => __('Amenities', 'otium-yachtfolio-sync'),
            'yf_has_crew'      => __('Crew', 'otium-yachtfolio-sync'),
            'yf_has_toys'      => __('Toys', 'otium-yachtfolio-sync'),
            'yf_has_gallery'   => __('Gallery', 'otium-yachtfolio-sync'),
        ];
    }

    /**
     * Which sections are present on this post.
     *
     * The marker is the item count when the section has content and an empty
     * string when it does not, so presence is an emptiness test, not a cast.
     *
     * @return array<string,bool> meta key => present
     */
    public static function breakdown(int $postId): array
    {
        $out = [];
        foreach (array_keys(self::SECTIONS) as $key) {
            $out[$key] = (string) get_post_meta($postId, $key, true) !== '';
        }
        return $out;
    }

    public static function of(int $postId): int
    {
        return count(array_filter(self::breakdown($postId)));
    }
}
