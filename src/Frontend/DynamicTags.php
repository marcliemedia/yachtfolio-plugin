<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Frontend;

use Otium\Yachtfolio\Mapping\AmenityText;
use Otium\Yachtfolio\Mapping\RatesTable;

/**
 * Bricks dynamic-data tags for the repeater fields.
 *
 * WHY THIS EXISTS
 * ---------------
 * A scalar `yf_*` field needs nothing from us: Bricks resolves `{cf_<meta_key>}`
 * against `get_post_meta()` for any key, registered or not
 * (themes/bricks/includes/integrations/dynamic-data/providers.php:547).
 *
 * The five repeater fields cannot work that way. `{cf_yf_rates}` renders
 * "18, Winter 2020/2021, 2020-10-01, …" — every cell of every row imploded into
 * one line. Bricks query loops cannot iterate plain serialised meta either, so
 * without a second post type or a JetEngine repeater definition there is no
 * builder-native way to show them.
 *
 * HOW
 * ---
 * Bricks returns an unregistered tag verbatim (`{yf_rates_table}` reaches the
 * page as literal text; providers.php:561), and there is no filter on its tag
 * registry. So we substitute on the rendered output instead, at priority 20 —
 * after Bricks' own pass at 10 has left our tags untouched. This is the
 * documented recipe for custom tags.
 *
 * The markup reuses the classes the Bricks template already styles
 * (`w-charter-rates-item*`, `w-single-yacht__details-item*`), so these blocks
 * inherit the site's design and add no stylesheet of their own.
 */
final class DynamicTags
{
    /** tag => builder-picker label */
    private const TAGS = [
        'yf_rates_table'       => 'Yachtfolio – Rates, every bookable season',
        'yf_crew_list'         => 'Yachtfolio – Crew',
        'yf_licences_list'     => 'Yachtfolio – Licences and registrations',
        'yf_requests_list'     => 'Yachtfolio – Special requests by season',
        'yf_unavailable_list'  => 'Yachtfolio – Seasons not available',
        'yf_equipment_extra'   => 'Yachtfolio – Equipment with no amenity switcher',
    ];

    public function register(): void
    {
        add_filter('bricks/dynamic_tags_list', [$this, 'add_to_picker']);
        add_filter('bricks/dynamic_data/render_content', [$this, 'on_content'], 20, 3);
        add_filter('bricks/frontend/render_data', [$this, 'on_data'], 20, 2);
        add_filter('bricks/dynamic_data/render_tag', [$this, 'on_tag'], 20, 3);
    }

    /**
     * Puts the tags in the builder's dynamic-data dropdown.
     *
     * @param array<int,array<string,string>> $tags
     * @return array<int,array<string,string>>
     */
    public function add_to_picker(array $tags): array
    {
        foreach (self::TAGS as $tag => $label) {
            $tags[] = [
                'name'  => '{' . $tag . '}',
                'label' => $label,
                'group' => 'Yachtfolio',
            ];
        }
        return $tags;
    }

    /** @param mixed $post */
    public function on_content(mixed $content, mixed $post = null, string $context = 'text'): mixed
    {
        return is_string($content) ? $this->substitute($content, $this->post_id($post)) : $content;
    }

    /** @param mixed $post */
    public function on_data(mixed $content, mixed $post = null): mixed
    {
        return is_string($content) ? $this->substitute($content, $this->post_id($post)) : $content;
    }

    /**
     * A field holding nothing but one of our tags (Bricks strips the braces
     * before this filter runs).
     *
     * @param mixed $post
     */
    public function on_tag(mixed $tag, mixed $post = null, string $context = 'text'): mixed
    {
        if (!is_string($tag)) {
            return $tag;
        }
        $bare = trim($tag, '{}');
        if (!isset(self::TAGS[$bare])) {
            return $tag;
        }
        return $this->render($bare, $this->post_id($post));
    }

    private function post_id(mixed $post): int
    {
        if ($post instanceof \WP_Post) {
            return (int) $post->ID;
        }
        if (is_numeric($post)) {
            return (int) $post;
        }
        return (int) get_the_ID();
    }

    private function substitute(string $content, int $postId): string
    {
        if (!str_contains($content, '{yf_')) {
            return $content;
        }

        foreach (array_keys(self::TAGS) as $tag) {
            $needle = '{' . $tag . '}';
            if (str_contains($content, $needle)) {
                $content = str_replace($needle, $this->render($tag, $postId), $content);
            }
        }

        return $content;
    }

    private function render(string $tag, int $postId): string
    {
        if ($postId <= 0) {
            return '';
        }

        return match ($tag) {
            'yf_rates_table'      => $this->rates($postId),
            'yf_crew_list'        => $this->crew($postId),
            'yf_licences_list'    => $this->licences($postId),
            'yf_requests_list'    => $this->requests($postId),
            'yf_unavailable_list' => $this->unavailable($postId),
            'yf_equipment_extra'  => $this->equipment_extra($postId),
            default               => '',
        };
    }

    /**
     * Rows of a repeater field, tolerating the three shapes the DB holds:
     * a real array, a serialised array, and a bare scalar (a one-row repeater).
     *
     * @return array<int,mixed>
     */
    private function rows(int $postId, string $key): array
    {
        $value = get_post_meta($postId, $key, true);

        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $un = maybe_unserialize($value);
            if (is_array($un)) {
                return $un;
            }
            return trim($value) !== '' ? [$value] : [];
        }

        return [];
    }

    /**
     * The rate table the three legacy boxes cannot express.
     *
     * high/low/shoulder can only ever show three numbers. ACAPELLA sells 14
     * rate rows and 4A's winter season runs 120k–135k, which the single
     * "shoulder" box has to flatten to one figure. Here every bookable season
     * keeps its own dates, range, currency and term.
     *
     * LAYOUT — measured, not guessed
     * ------------------------------
     * The first version reused the legacy row: season and price side by side in
     * a 401 px sidebar. Measurement showed three faults.
     *
     *   1. The price had no visual priority — identical colour to the season
     *      name (both #535353), separated only by 22 px vs 16.5 px of size.
     *      The most consequential number on the page was not the anchor.
     *   2. "Summer 2027" beside "€92,000 – €103,000" filled the panel edge to
     *      edge, one longer season name away from wrapping.
     *   3. "MYBA" repeated on every row, identical, and it is already stated in
     *      Charter terms.
     *
     * So each row now stacks into three ranked levels — quiet label, loud
     * number, small detail — the price takes the brand colour, the term becomes
     * one footnote, and a range prints its currency once.
     *
     * The status chip is derived only from dates and the feed's own
     * `seasons_unavailable`, never asserted: a customer scanning three seasons
     * otherwise has no cue which one they can act on.
     */
    private function rates(int $postId): string
    {
        $rows = RatesTable::upcoming($this->rows($postId, 'yf_rates'));
        if ($rows === []) {
            return '';
        }

        $unavailable = array_map(
            static fn($v): string => is_array($v) ? (string) ($v['season'] ?? reset($v)) : (string) $v,
            $this->rows($postId, 'yf_seasons_unavailable')
        );
        $unavailable = array_filter(array_map('trim', $unavailable));

        $today = current_time('Y-m-d');
        $currentIndex = null;
        $nextIndex = null;
        foreach ($rows as $i => $row) {
            $start = (string) ($row['season_start'] ?? '');
            $end   = (string) ($row['season_end'] ?? '');
            if ($currentIndex === null && $start !== '' && $end !== '' && $start <= $today && $today <= $end) {
                $currentIndex = $i;
            }
            if ($nextIndex === null && $start > $today) {
                $nextIndex = $i;
            }
        }
        // Only one row ever carries a chip: the one the customer can act on.
        $chipIndex = $currentIndex ?? $nextIndex;

        $terms = [];
        foreach ($rows as $row) {
            $term = trim((string) ($row['term'] ?? ''));
            if ($term !== '') {
                $terms[$term] = true;
            }
        }
        $sharedTerm = count($terms) === 1 ? (string) array_key_first($terms) : '';

        $out = '';
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['season_label'] ?? ''));
            $dates = $this->date_range((string) ($row['season_start'] ?? ''), (string) ($row['season_end'] ?? ''));
            $rate  = $this->rate_range($row);

            $isUnavailable = $label !== '' && in_array($label, $unavailable, true);
            $chip = '';
            if ($isUnavailable) {
                $chip = ['text' => __('Not available', 'otium-yachtfolio-sync'), 'kind' => 'off'];
            } elseif ($i === $chipIndex) {
                $chip = [
                    'text' => $i === $currentIndex
                        ? __('Current season', 'otium-yachtfolio-sync')
                        : __('Next season', 'otium-yachtfolio-sync'),
                    'kind' => 'on',
                ];
            }

            $meta = $dates;
            if ($sharedTerm === '' && trim((string) ($row['term'] ?? '')) !== '') {
                // Terms differ between seasons, so they cannot become a footnote.
                $meta = trim($meta . ' · ' . trim((string) $row['term']));
            }

            $classes = 'oy-yf-rate' . ($isUnavailable ? ' oy-yf-rate--off' : '');

            $out .= '<div class="brxe-div ' . $classes . '">'
                . '<span class="brxe-text-basic oy-yf-rate__season">' . esc_html($label)
                . (is_array($chip)
                    ? '<span class="oy-yf-rate__chip oy-yf-rate__chip--' . $chip['kind'] . '">' . esc_html($chip['text']) . '</span>'
                    : '')
                . '</span>'
                . '<span class="brxe-text-basic oy-yf-rate__price">' . esc_html($rate) . '</span>'
                . ($meta !== '' ? '<span class="brxe-text-basic oy-yf-rate__meta">' . esc_html($meta) . '</span>' : '')
                . '</div>';
        }

        /**
         * Footnote: the contract term, and the tax the customer also pays.
         *
         * Tax belongs at the point of decision, not three accordions away — the
         * quoted figure is not what leaves their account. The full picture
         * (Greek VAT, APA, payment mechanics) stays in the rate card, which is
         * the only field that carries it.
         */
        $footnote = [];
        if ($sharedTerm !== '') {
            $footnote[] = sprintf(
                /* translators: %s is a charter contract type, e.g. MYBA. */
                __('Rates quoted on %s terms', 'otium-yachtfolio-sync'),
                $sharedTerm
            );
        }
        $tax = trim((string) get_post_meta($postId, 'yf_tax_comments', true));
        if ($tax !== '') {
            $footnote[] = $tax;
        }

        if ($footnote !== []) {
            $out .= '<span class="brxe-text-basic oy-yf-rate__note">'
                . esc_html(implode(' · ', $footnote))
                . '</span>';
        }

        return $out;
    }

    /**
     * "1 May – 30 Sep 2026", collapsing the year when both ends share it.
     */
    private function date_range(string $start, string $end): string
    {
        $fmt = static function (string $d, bool $withYear): string {
            $ts = strtotime($d);
            return $ts === false ? '' : date_i18n($withYear ? 'j M Y' : 'j M', $ts);
        };

        if ($start === '' && $end === '') {
            return '';
        }
        if ($start === '' || $end === '') {
            return $fmt($start !== '' ? $start : $end, true);
        }

        $sameYear = substr($start, 0, 4) === substr($end, 0, 4);

        return $fmt($start, !$sameYear) . ' – ' . $fmt($end, true);
    }

    /**
     * "120,000 – 135,000 USD", or one figure when min equals max.
     *
     * The single-figure case is the whole reason the old page showed
     * "$120,000 - $120,000": a range of one value is not a range.
     *
     * @param array<string,mixed> $row
     */
    private function rate_range(array $row): string
    {
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        $min = $this->amount($row['min_rate'] ?? null);
        $max = $this->amount($row['max_rate'] ?? null);

        if ($min === null && $max === null) {
            return '';
        }
        if ($min === null || $max === null) {
            $one = $min ?? $max;
            return $this->money((float) $one, $currency);
        }
        if (abs($min - $max) < 0.01) {
            return $this->money($min, $currency);
        }

        /**
         * Currency once, not twice.
         *
         * "€88,000 – €98,000" spends 14 px of a 401 px sidebar repeating a
         * symbol the reader already has, and a symbol mid-string reads as two
         * separate prices rather than one range.
         */
        return $this->money($min, $currency) . ' – ' . number_format($max, 0, '.', ',')
            . (isset(['EUR' => 1, 'USD' => 1, 'GBP' => 1][$currency]) || $currency === '' ? '' : ' ' . $currency);
    }

    private function amount(mixed $v): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        return (float) $v;
    }

    private function money(float $amount, string $currency): string
    {
        $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];
        $number = number_format($amount, 0, '.', ',');

        if (isset($symbols[$currency])) {
            // The site's existing rate boxes are symbol-first ("$103,000").
            return $symbols[$currency] . $number;
        }

        return $currency === '' ? $number : $number . ' ' . $currency;
    }

    /**
     * Crew, the decisive factor in a luxury charter — 9 people on ACAPELLA,
     * each with name, rank, nationality and a several-hundred-word profile.
     *
     * MARKUP SHAPE MATTERS HERE
     * -------------------------
     * The first version emitted the name row and the bio as two SIBLINGS of the
     * grid, so the container's row gap fell between every line equally: nine
     * people read as one undifferentiated column of text.
     *
     * Each member is now one element, so the gap separates PEOPLE, and the three
     * pieces of information are ranked instead of merely stacked:
     *
     *   name         prominent, the thing you scan for
     *   rank · flag  secondary, one quiet line
     *   profile      body copy
     *
     * The classes are styled by the crew block's own scoped CSS in the Bricks
     * template, so a designer can retune it in the builder.
     */
    private function crew(int $postId): string
    {
        $out = '';
        foreach ($this->rows($postId, 'yf_crew') as $member) {
            if (!is_array($member)) {
                continue;
            }

            $name = trim(((string) ($member['first_name'] ?? '')) . ' ' . ((string) ($member['last_name'] ?? '')));
            $position = trim((string) ($member['position'] ?? ''));
            $nationality = trim((string) ($member['nationality'] ?? ''));
            $description = trim((string) ($member['description'] ?? ''));
            $tba = in_array((string) ($member['tba'] ?? ''), ['1', 'true'], true);

            if ($name === '' && $position === '') {
                continue;
            }
            if ($tba && $name === '') {
                $name = __('To be announced', 'otium-yachtfolio-sync');
            }

            $rank = implode(' · ', array_filter([$position, $nationality]));

            $out .= '<div class="brxe-div oy-yf-crew__member">'
                . '<span class="brxe-text-basic oy-yf-crew__name">' . esc_html($name) . '</span>';

            if ($rank !== '') {
                $out .= '<span class="brxe-text-basic oy-yf-crew__rank">' . esc_html($rank) . '</span>';
            }

            if ($description !== '') {
                $out .= '<div class="brxe-div oy-yf-crew__bio"><p>'
                    . implode('</p><p>', array_map('esc_html', $this->paragraphs($description)))
                    . '</p></div>';
            }

            $out .= '</div>';
        }

        return $out;
    }

    /**
     * Equipment the switcher grid cannot express.
     *
     * `equipment` is a comma-separated feature list, and AmenityText now turns
     * most of it into the site's own amenity switchers — which is why the
     * "Entertainment & connectivity" accordion was removed as duplication. What
     * is left over is not amenity data at all: on 4A it reads "Stabilisers
     * underway, Stabilisers at anchor, Master on main deck, Private Owner deck",
     * which are hull and layout facts. Hence this renders inside Specifications.
     */
    private function equipment_extra(int $postId): string
    {
        $text = (string) get_post_meta($postId, 'yf_equipment_list', true);
        $extra = AmenityText::unmatched($text);

        return $extra === [] ? '' : esc_html(implode(', ', $extra));
    }

    /** @return array<int,string> */
    private function paragraphs(string $text): array
    {
        $parts = preg_split('/\R{2,}/', $text) ?: [$text];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $s): bool => $s !== ''));
    }

    /**
     * "Is licensed for Greece — Summer 2027". The status is a sentence fragment
     * in the feed ("Is", "Can be"), so it reads as the start of the line.
     */
    private function licences(int $postId): string
    {
        $out = '';
        foreach ($this->rows($postId, 'yf_licences') as $row) {
            if (!is_array($row)) {
                continue;
            }

            $licence = trim((string) ($row['licence'] ?? ''));
            if ($licence === '') {
                continue;
            }
            $status = trim((string) ($row['status'] ?? ''));
            $season = trim((string) ($row['season'] ?? ''));

            $out .= '<div class="brxe-div w-single-yacht__details-item">'
                . '<span class="brxe-text-basic w-single-yacht__details-item-label">'
                . esc_html(trim($status . ' ' . $licence))
                . '</span>'
                . '<span class="brxe-text-basic w-single-yacht__details-item-desc">' . esc_html($season) . '</span>'
                . '</div>';
        }

        return $out;
    }

    private function requests(int $postId): string
    {
        $out = '';
        foreach ($this->rows($postId, 'yf_special_requests') as $row) {
            $season = is_array($row) ? trim((string) ($row['season'] ?? '')) : '';
            $requests = is_array($row) ? trim((string) ($row['requests'] ?? '')) : trim((string) $row);
            if ($requests === '') {
                continue;
            }

            $out .= '<div class="brxe-div w-single-yacht__details-item">'
                . '<span class="brxe-text-basic w-single-yacht__details-item-label">' . esc_html($season) . '</span>'
                . '<span class="brxe-text-basic w-single-yacht__details-item-desc">' . esc_html($requests) . '</span>'
                . '</div>';
        }

        return $out;
    }

    private function unavailable(int $postId): string
    {
        $seasons = [];
        foreach ($this->rows($postId, 'yf_seasons_unavailable') as $row) {
            $label = is_array($row) ? trim((string) ($row['season'] ?? reset($row))) : trim((string) $row);
            if ($label !== '') {
                $seasons[] = $label;
            }
        }

        if ($seasons === []) {
            return '';
        }

        return '<div class="brxe-div w-single-yacht__details-item">'
            . '<span class="brxe-text-basic w-single-yacht__details-item-label">'
            . esc_html__('Not available', 'otium-yachtfolio-sync')
            . '</span>'
            . '<span class="brxe-text-basic w-single-yacht__details-item-desc">' . esc_html(implode(', ', $seasons)) . '</span>'
            . '</div>';
    }
}
