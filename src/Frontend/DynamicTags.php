<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Frontend;

use Otium\Yachtfolio\Mapping\AmenityText;

/**
 * Bricks dynamic-data tags for the repeater fields.
 *
 * WHY THIS EXISTS
 * ---------------
 * A scalar `yf_*` field needs nothing from us: Bricks resolves `{cf_<meta_key>}`
 * against `get_post_meta()` for any key, registered or not
 * (themes/bricks/includes/integrations/dynamic-data/providers.php:547).
 *
 * The repeater fields cannot work that way. `{cf_yf_crew}` renders
 * "Ana, Horvat, Chef, …" — every cell of every row imploded into
 * one line. Bricks query loops cannot iterate plain serialised meta either, so
 * without a second post type or a JetEngine repeater definition there is no
 * builder-native way to show them.
 *
 * HOW
 * ---
 * Bricks returns an unregistered tag verbatim (`{yf_crew_list}` reaches the
 * page as literal text; providers.php:561), and there is no filter on its tag
 * registry. So we substitute on the rendered output instead, at priority 20 —
 * after Bricks' own pass at 10 has left our tags untouched. This is the
 * documented recipe for custom tags.
 *
 * The markup reuses the classes the Bricks template already styles
 * (`w-single-yacht__details-item*`), so these blocks
 * inherit the site's design and add no stylesheet of their own.
 */
final class DynamicTags
{
    /** tag => builder-picker label */
    private const TAGS = [
        // No rate tag: the page shows the editable rate boxes, never the feed's
        // season rows (client e-mail 2026-11-05).
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
