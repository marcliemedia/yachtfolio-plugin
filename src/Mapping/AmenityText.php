<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Mapping;

/**
 * Amenity switchers derived from the feed's free-text equipment fields.
 *
 * WHY
 * ---
 * EquipmentMap covers the vendor's structured `equipments` table, which has
 * exactly 10 rows and only 4 counterparts among the site's 37 `amenities_*`
 * switchers. Meanwhile three free-text fields carry far more:
 *
 *   equipment_list  "Watermakers, Air conditioning, Stabilisers underway,
 *                    Aft platform, Starlink, WiFi connection"
 *   av_facilities   "Large plasma TV, Satellite TV, DVD, CD, music system…"
 *   communications  "WiFi"
 *
 * A brochure-only yacht therefore arrived with 0 switchers on while its own
 * text listed six things the site has switchers for.
 *
 * SAFETY RULES
 * ------------
 * 1. Only ever turn a switcher ON. Absence of a phrase is not evidence of
 *    absence: 31 switchers have no API source and are editor-owned, so writing
 *    'false' would erase hand-entered content.
 * 2. Match per comma-separated segment, on word boundaries. Loose substring
 *    matching turns "no jacuzzi" into a jacuzzi and "barbecue" into a bar.
 * 3. Skip a segment that reads as a denial ("no …", "without …", "n/a").
 * 4. Never invent a switcher. Every target below was checked to exist in the
 *    database on 2026-08-30.
 */
final class AmenityText
{
    /**
     * amenity meta key => phrases that prove it.
     *
     * Phrases are matched with word boundaries, so "cd" does not fire inside
     * "recorded" and "bar" does not fire inside "barbecue".
     *
     * @return array<string,array<int,string>>
     */
    public static function phrases(): array
    {
        return [
            'amenities_watermaker'       => ['watermaker', 'watermakers', 'water maker'],
            'amenities_ac'               => ['air conditioning', 'air-conditioning', 'aircon', 'a/c'],
            'amenities_jacuzzi'          => ['jacuzzi', 'hot tub', 'spa pool'],
            'amenities_wifi'             => ['wifi', 'wi-fi', 'wifi connection', 'wireless internet'],
            'amenities_starlink-internet' => ['starlink'],
            'amenities_plasma-tv'        => ['plasma tv', 'plasma screen'],
            'amenities_tv'               => ['sat tv', 'satellite tv', 'smart tv', 'tv in saloon', 'tv in salon', 'led tv', 'flat screen tv'],
            'amenities_cd-dvd'           => ['dvd', 'cd player', 'blu-ray'],
            'amenities_mucsic-system'    => ['music system', 'music centre', 'music center'],
            'amenities_sound-system'     => ['sound system', 'bluetooth speakers', 'speakers'],
            'amenities_bluetooth'        => ['bluetooth'],
            'amenities_hookups'          => ['hookups', 'hook-ups', 'ipod', 'iphone dock', 'smartphone device'],
            'amenities_gym-equipment'    => ['gym', 'gym equipment', 'exercise equipment', 'fitness equipment'],
            'amenities_swimming-platform' => ['aft platform', 'swimming platform', 'bathing platform', 'swim platform'],
            'amenities_hydraulic-swimming' => ['hydraulic swimming platform', 'hydraulic platform', 'hydraulic swim platform'],
            'amenities_playstation'      => ['playstation', 'play station', 'xbox'],
            'amenities_ice-maker'        => ['ice maker', 'ice-maker', 'icemaker'],
            'amenities_coffee-machine'   => ['coffee machine', 'espresso machine', 'nespresso'],
            'amenities_safebox'          => ['safe box', 'safebox', 'in-room safe', 'cabin safe'],
            'amenities_sauna'            => ['sauna'],
            'amenities_library'          => ['library', 'book collection'],
            'amenities_board-games'      => ['board games', 'boardgames'],
            'amenities_bar'              => ['bar', 'cocktail bar', 'wet bar'],
            'amenities_outside-dining'   => ['outside dining', 'alfresco dining', 'al fresco dining', 'outdoor dining'],
            'amenities_sunbed'           => ['sunbed', 'sunbeds', 'sun bed', 'sun pads', 'sun loungers'],
            'amenities_shower-on-deck'   => ['deck shower', 'shower on deck', 'transom shower'],
            'amenities_hairdryer'        => ['hairdryer', 'hair dryer', 'hair-dryer'],
            'amenities_toiletries'       => ['toiletries'],
            'amenities_passerelle-gangway' => ['passerelle', 'gangway'],
            'amenities_aft-lounge'       => ['aft lounge', 'aft deck lounge'],
            'amenities_bow-lounge'       => ['bow lounge', 'bow seating', 'foredeck lounge'],
            'amenities_lounge-area'      => ['lounge area', 'lounging area'],
            'amenities_bbq'              => ['bbq', 'barbecue', 'barbeque'],
        ];
    }

    /** Fields whose prose is scanned, in priority order. */
    public const SOURCE_FIELDS = ['equipment', 'av_facilities', 'communications'];

    /**
     * Amenity keys this matcher is allowed to set.
     *
     * Ownership::is_addon_only() treats any amenities_* key outside the mapped
     * set as editor-owned and rejects writes to it, which is the safety net
     * that keeps the feed away from the 31 switchers it has no source for.
     * These keys DO have a source, so they join the api-owned set.
     *
     * @return array<int,string>
     */
    public static function targets(): array
    {
        return array_keys(self::phrases());
    }

    /**
     * Reads as a denial, so nothing in it counts as proof.
     */
    private static function is_denial(string $segment): bool
    {
        return (bool) preg_match('/\b(no|not|without|none|n\/a|excluded|unavailable)\b/i', $segment);
    }

    /**
     * The individual claims inside the prose fields.
     *
     * Commas, semicolons, newlines and bullets all separate claims in this feed.
     *
     * @param array<int,string> $texts
     * @return array<int,string>
     */
    public static function segments(array $texts): array
    {
        $segments = [];
        foreach ($texts as $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            foreach (preg_split('/[,;\r\n]+|(?:^|\s)[-•]\s/u', $text) ?: [] as $segment) {
                $segment = trim((string) $segment, " \t\n\r\0\x0B.");
                if ($segment !== '' && !in_array($segment, $segments, true)) {
                    $segments[] = $segment;
                }
            }
        }
        return $segments;
    }

    /**
     * Switchers proven by these texts.
     *
     * @param array<int,string> $texts
     * @return array<string,string> amenity key => the segment that proved it
     */
    public static function detect(array $texts): array
    {
        $segments = self::segments($texts);

        $found = [];
        foreach (self::phrases() as $key => $phrases) {
            foreach ($segments as $segment) {
                if (self::is_denial($segment)) {
                    continue;
                }
                foreach ($phrases as $phrase) {
                    $pattern = '/(?<![\w\-])' . preg_quote($phrase, '/') . '(?![\w\-])/iu';
                    if (preg_match($pattern, $segment) === 1) {
                        $found[$key] = $segment;
                        continue 3;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Does any phrase in the table prove something in this segment?
     */
    public static function matches_any(string $segment): bool
    {
        if (self::is_denial($segment)) {
            return false;
        }
        foreach (self::phrases() as $phrases) {
            foreach ($phrases as $phrase) {
                if (preg_match('/(?<![\w\-])' . preg_quote($phrase, '/') . '(?![\w\-])/iu', $segment) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Claims in one comma-separated list that no switcher can express.
     *
     * detect() deliberately records one segment per switcher as its evidence,
     * so it cannot answer "is this segment redundant?" — "SAT TV", "Smart TV"
     * and "TV in saloon" all prove amenities_tv but only the first is returned.
     * This asks the question per segment instead.
     *
     * On 4A the answer is "Stabilisers underway, Stabilisers at anchor, Master
     * on main deck, Private Owner deck" — which is why these land in
     * Specifications rather than Amenities: they are hull and layout facts, not
     * things aboard.
     *
     * @return array<int,string>
     */
    public static function unmatched(string $text): array
    {
        return array_values(array_filter(
            self::segments([$text]),
            static fn(string $s): bool => !self::matches_any($s)
        ));
    }

    /**
     * How much of a prose field the switcher grid already says, 0.0–1.0.
     *
     * `av_facilities` is one sentence with internal commas, not a list:
     *
     *   "Large plasma TV, Satellite TV, DVD, CD, music system in the salon,
     *    Bluetooth connection, iPod / smartphone device hookups throughout the
     *    flybridge, saloon, aft deck, rooms with individual access from iPad."
     *
     * Printing the unmatched fragments of that would yield "CD", "saloon",
     * "aft deck" — debris, not information. So the choice is binary: show the
     * sentence whole, or not at all. On ACAPELLA six of its ten claims are
     * already switchers standing right above it, which is duplication; a yacht
     * whose text goes beyond the switchers still gets its note in full.
     */
    public static function coverage(string $text): float
    {
        $segments = self::segments([$text]);
        if ($segments === []) {
            return 1.0; // nothing to say, so nothing is missing
        }

        $matched = 0;
        foreach ($segments as $segment) {
            if (self::matches_any($segment)) {
                $matched++;
            }
        }

        return $matched / count($segments);
    }

    /** Above this, a prose note is judged to repeat the switcher grid. */
    public const COVERAGE_REDUNDANT = 0.6;
}
