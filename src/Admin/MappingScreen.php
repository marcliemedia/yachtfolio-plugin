<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Admin;

use Otium\Yachtfolio\Mapping\AreaAlias;
use Otium\Yachtfolio\Mapping\EquipmentMap;
use Otium\Yachtfolio\Mapping\FieldMap;
use Otium\Yachtfolio\Mapping\MappingReport;
use Otium\Yachtfolio\Mapping\TypeResolver;
use Otium\Yachtfolio\Plugin;

final class MappingScreen
{
    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_post_oy_yf_save_mapping', [$this, 'save']);
    }

    public function render(): void
    {
        Menu::require_cap();

        Menu::open_page(
            Menu::SLUG_MAPPING,
            __('Mapping', 'otium-yachtfolio-sync'),
            __('How feed values are translated into meta keys and taxonomy terms. Unmapped values are reported, never guessed.', 'otium-yachtfolio-sync')
        );

        $this->unmapped_panel();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('oy_yf_mapping');
        echo '<input type="hidden" name="action" value="oy_yf_save_mapping">';

        /* ---- equipment ---- */
        echo '<h2 class="oy-section__title" style="margin:var(--oy-8) 0 var(--oy-2)">' . esc_html__('Equipment → amenity switcher', 'otium-yachtfolio-sync') . '</h2>';
        echo '<p class="description">' . esc_html__('The feed carries exactly 10 equipment types, so only 4 of the 35 amenities_* switchers can be driven by the API. The remaining 31 stay editor-owned and the sync never writes them — otherwise a missing entry would switch off a real amenity.', 'otium-yachtfolio-sync') . '</p>';

        $names = $this->plugin->reference()->equipment_names();
        $equipment = EquipmentMap::effective();

        echo '<table class="oy-table"><thead><tr>'
            . '<th>' . esc_html__('Feed equipment', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Amenity meta key', 'otium-yachtfolio-sync') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($names as $id => $name) {
            printf(
                '<tr><td><strong>%s</strong> <span class="description">(id %d)</span></td>'
                . '<td><input type="text" class="regular-text" name="equipment[%d]" value="%s" placeholder="%s"></td></tr>',
                esc_html($name),
                (int) $id,
                (int) $id,
                esc_attr((string) ($equipment[$id] ?? '')),
                esc_attr__('leave empty to ignore', 'otium-yachtfolio-sync')
            );
        }
        echo '</tbody></table>';

        /* ---- yacht type ---- */
        echo '<h2 class="oy-section__title" style="margin:var(--oy-8) 0 var(--oy-2)">' . esc_html__('Yacht type rules', 'otium-yachtfolio-sync') . '</h2>';
        echo '<p class="description">' . esc_html__('Matched against sail_power, superstructure, hull_configuration and rig (lowercased). Catamaran, Gulet and Mini cruiser have no discriminator in the feed: those yachts keep their existing term and are flagged instead of guessed.', 'otium-yachtfolio-sync') . '</p>';
        $this->pair_table('type', TypeResolver::effective(), __('Feed value', 'otium-yachtfolio-sync'), __('yacht-type term', 'otium-yachtfolio-sync'));

        /* ---- areas ---- */
        echo '<h2 class="oy-section__title" style="margin:var(--oy-8) 0 var(--oy-2)">' . esc_html__('Operating area → destination term', 'otium-yachtfolio-sync') . '</h2>';
        echo '<p class="description">' . esc_html__('The feed carries 107 areas in 26 groups; map them onto existing destination terms to avoid near-duplicates. Unmapped names are used verbatim.', 'otium-yachtfolio-sync') . '</p>';
        $this->pair_table('area', AreaAlias::effective(), __('Feed area name', 'otium-yachtfolio-sync'), __('yacht-destination term', 'otium-yachtfolio-sync'));

        /* ---- field map ---- */
        echo '<h2 class="oy-section__title" style="margin:var(--oy-8) 0 var(--oy-2)">' . esc_html__('Field map', 'otium-yachtfolio-sync') . '</h2>';
        echo '<p class="description">' . esc_html__('Source is a specification key from the feed (dotted paths allowed, e.g. video.video_url). Transform names come from the transform catalogue. Owner "addon" means the sync never touches the field.', 'otium-yachtfolio-sync') . '</p>';

        echo '<table class="oy-table"><thead><tr>'
            . '<th>' . esc_html__('Meta key', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Source', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Transform', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Owner', 'otium-yachtfolio-sync') . '</th>'
            . '</tr></thead><tbody>';

        foreach (FieldMap::effective() as $metaKey => $entry) {
            $src = is_array($entry['src']) ? implode(', ', $entry['src']) : (string) $entry['src'];
            printf(
                '<tr><td><code>%s</code></td>'
                . '<td><input type="text" class="regular-text" name="field[%1$s][src]" value="%s"></td>'
                . '<td><input type="text" class="small-text" name="field[%1$s][tf]" value="%s"></td>'
                . '<td><select name="field[%1$s][owner]"><option value="api" %s>api</option><option value="addon" %s>addon</option></select></td></tr>',
                esc_attr((string) $metaKey),
                esc_attr($src),
                esc_attr((string) $entry['tf']),
                selected($entry['owner'], 'api', false),
                selected($entry['owner'], 'addon', false)
            );
        }
        echo '</tbody></table>';

        echo '<div class="oy-savebar">';
        echo '<button type="submit" class="oy-btn oy-btn--danger" name="oy_yf_reset" value="1" onclick="return confirm(\'' . esc_js(__('Reset every mapping table to its defaults?', 'otium-yachtfolio-sync')) . '\')">'
            . esc_html__('Reset to defaults', 'otium-yachtfolio-sync') . '</button>';
        echo '<button type="submit" class="oy-btn oy-btn--primary">' . esc_html__('Save mapping', 'otium-yachtfolio-sync') . '</button>';
        echo '</div>';

        echo '</form>';
        Menu::close_page();
    }

    public function save(): void
    {
        Menu::require_cap();
        check_admin_referer('oy_yf_mapping');

        $post = wp_unslash($_POST);

        if (!empty($post['oy_yf_reset'])) {
            FieldMap::reset();
            EquipmentMap::reset();
            AreaAlias::reset();
            TypeResolver::reset();
            Menu::flash(__('Mapping tables reset to defaults.', 'otium-yachtfolio-sync'));
            Menu::go(Menu::SLUG_MAPPING);
            return;
        }

        $equipment = [];
        foreach ((array) ($post['equipment'] ?? []) as $id => $key) {
            $key = sanitize_key((string) $key);
            if ($key !== '') {
                $equipment[(int) $id] = $key;
            }
        }
        EquipmentMap::save($equipment);

        AreaAlias::save($this->pairs((array) ($post['area_from'] ?? []), (array) ($post['area_to'] ?? [])));
        TypeResolver::save($this->pairs((array) ($post['type_from'] ?? []), (array) ($post['type_to'] ?? [])));

        $fields = [];
        foreach ((array) ($post['field'] ?? []) as $metaKey => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $src = trim((string) ($entry['src'] ?? ''));
            $fields[(string) $metaKey] = [
                'src'   => str_contains($src, ',') ? array_map('trim', explode(',', $src)) : $src,
                'tf'    => trim((string) ($entry['tf'] ?? 'raw')),
                'owner' => ($entry['owner'] ?? 'api') === 'addon' ? 'addon' : 'api',
            ];
        }
        FieldMap::save($fields);

        Menu::flash(__('Mapping saved.', 'otium-yachtfolio-sync'));
        Menu::go(Menu::SLUG_MAPPING);
    }

    /* ------------------------------------------------------------------ */

    private function unmapped_panel(): void
    {
        $report = MappingReport::load();
        $meta = MappingReport::meta();

        echo '<div class="oy-card oy-card--flag"><div class="oy-card__head"><h2 class="oy-card__title">' . esc_html__('Values the last run could not map', 'otium-yachtfolio-sync') . '</h2></div><div class="oy-card__body">';

        if ($report->is_empty()) {
            echo '<p>' . esc_html__('Nothing outstanding.', 'otium-yachtfolio-sync') . '</p></div></div>';
            return;
        }

        if ($meta['generated_at'] !== '') {
            echo '<p class="description">' . esc_html(sprintf(
                __('Run %1$s, %2$s', 'otium-yachtfolio-sync'),
                $meta['run_id'],
                $meta['generated_at']
            )) . '</p>';
        }

        echo '<table class="oy-table"><thead><tr>'
            . '<th>' . esc_html__('Kind', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Value', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Seen', 'otium-yachtfolio-sync') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($report->all() as $kind => $values) {
            foreach ($values as $value => $count) {
                printf(
                    '<tr><td><code>%s</code></td><td>%s</td><td>%d×</td></tr>',
                    esc_html((string) $kind),
                    esc_html((string) $value),
                    (int) $count
                );
            }
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * @param array<string,string> $map
     */
    private function pair_table(string $prefix, array $map, string $fromLabel, string $toLabel): void
    {
        echo '<table class="oy-table"><thead><tr>'
            . '<th>' . esc_html($fromLabel) . '</th>'
            . '<th>' . esc_html($toLabel) . '</th>'
            . '</tr></thead><tbody>';

        $rows = $map;
        $rows[''] = ''; // one blank row to add a new mapping

        $index = 0;
        foreach ($rows as $from => $to) {
            printf(
                '<tr><td><input type="text" class="regular-text" name="%1$s_from[%2$d]" value="%3$s"></td>'
                . '<td><input type="text" class="regular-text" name="%1$s_to[%2$d]" value="%4$s"></td></tr>',
                esc_attr($prefix),
                $index,
                esc_attr((string) $from),
                esc_attr((string) $to)
            );
            $index++;
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<int,string> $from
     * @param array<int,string> $to
     * @return array<string,string>
     */
    private function pairs(array $from, array $to): array
    {
        $out = [];
        foreach ($from as $index => $key) {
            $key = trim((string) $key);
            $value = trim((string) ($to[$index] ?? ''));
            if ($key !== '' && $value !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
