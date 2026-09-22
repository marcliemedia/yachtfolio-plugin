<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Admin;

use Otium\Yachtfolio\Mapping\AreaAlias;
use Otium\Yachtfolio\Mapping\EquipmentMap;
use Otium\Yachtfolio\Mapping\FieldMap;
use Otium\Yachtfolio\Mapping\TypeResolver;
use Otium\Yachtfolio\Plugin;

final class ToolsScreen
{
    /** Legacy keys reported but never touched without a decision. */
    private const LEGACY_KEYS = [
        'amenities',
        'amenities_field',
        'amenities_lounge',
        'amenities_toys',
        'toys',
        '1amenities',
        '_bak_20260815_seamless_content',
        '_bak_20260815_seamless_key_features',
        '_bak_20260815_seamless_short_description',
    ];

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_post_oy_yf_tool', [$this, 'handle']);
    }

    public function render(): void
    {
        Menu::require_cap();

        Menu::open_page(
            Menu::SLUG_TOOLS,
            __('Tools', 'otium-yachtfolio-sync'),
            __('One-off maintenance operations. None of them publish or delete yacht content.', 'otium-yachtfolio-sync')
        );

        /* actions */
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="oy-actions--panel">';
        wp_nonce_field('oy_yf_tools');
        echo '<input type="hidden" name="action" value="oy_yf_tool">';
        echo '<div class="oy-actions">';
        $this->button('check', __('Check connection', 'otium-yachtfolio-sync'));
        $this->button('reference', __('Refresh reference cache', 'otium-yachtfolio-sync'));
        $this->button('recompute', __('Recompute hashes', 'otium-yachtfolio-sync'));
        $this->button('prune_media', __('Prune media ledger', 'otium-yachtfolio-sync'));
        $this->button('budget', __('Reset call budget', 'otium-yachtfolio-sync'));
        $this->button('export_mapping', __('Export mapping JSON', 'otium-yachtfolio-sync'));
        echo '</div>';
        echo '<div class="oy-actions" style="margin-top:var(--oy-3)">';
        printf(
            '<input type="number" name="yacht" class="small-text" placeholder="%s"> ',
            esc_attr__('feed id', 'otium-yachtfolio-sync')
        );
        $this->button('reimport_media', __('Re-import media for this yacht', 'otium-yachtfolio-sync'));
        echo '</div>';
        echo '</form>';

        /* reference ages */
        echo '<h2 class="oy-section__title" style="margin:var(--oy-8) 0 var(--oy-3)">' . esc_html__('Reference cache', 'otium-yachtfolio-sync') . '</h2>';
        echo '<table class="oy-table"><thead><tr><th>' . esc_html__('Table', 'otium-yachtfolio-sync')
            . '</th><th>' . esc_html__('Rows', 'otium-yachtfolio-sync')
            . '</th><th>' . esc_html__('Age', 'otium-yachtfolio-sync') . '</th></tr></thead><tbody>';
        $counts = $this->plugin->reference()->counts();
        $ages = $this->plugin->reference()->ages();
        foreach ($counts as $table => $count) {
            if ($table === 'errors') {
                continue;
            }
            $age = (int) ($ages[$table] ?? -1);
            printf(
                '<tr><td><code>%s</code></td><td>%d</td><td>%s</td></tr>',
                esc_html((string) $table),
                (int) $count,
                $age < 0 ? esc_html__('never', 'otium-yachtfolio-sync') : esc_html(human_time_diff(time() - $age) . ' ' . __('ago', 'otium-yachtfolio-sync'))
            );
        }
        echo '</tbody></table>';

        /* linking */
        echo '<h2>' . esc_html__('Link existing yachts', 'otium-yachtfolio-sync') . '</h2>';
        $suggestions = $this->plugin->linker()->suggest(50);

        if ($suggestions === []) {
            echo '<p>' . esc_html__('No unlinked matches. Run the index pass first if the feed has never been fetched.', 'otium-yachtfolio-sync') . '</p>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('oy_yf_tools');
            echo '<input type="hidden" name="action" value="oy_yf_tool">';
            echo '<table class="oy-table"><thead><tr>'
                . '<th>' . esc_html__('WordPress post', 'otium-yachtfolio-sync') . '</th>'
                . '<th>' . esc_html__('Feed yacht', 'otium-yachtfolio-sync') . '</th>'
                . '<th>' . esc_html__('Score', 'otium-yachtfolio-sync') . '</th>'
                . '<th></th></tr></thead><tbody>';

            foreach ($suggestions as $suggestion) {
                printf(
                    '<tr><td><a href="%s">%s</a></td><td>%s <span class="description">(%d)</span></td>'
                    . '<td>%d%% <span class="oy-pill">%s</span></td>'
                    . '<td><button type="submit" class="oy-btn oy-btn--sm" name="oy_yf_do" value="link:%d:%d">%s</button></td></tr>',
                    esc_url((string) get_edit_post_link((int) $suggestion['post_id'])),
                    esc_html((string) $suggestion['post_title']),
                    esc_html((string) $suggestion['yf_name']),
                    (int) $suggestion['yf_id'],
                    (int) $suggestion['score'],
                    esc_html((string) $suggestion['kind']),
                    (int) $suggestion['yf_id'],
                    (int) $suggestion['post_id'],
                    esc_html__('Link', 'otium-yachtfolio-sync')
                );
            }
            echo '</tbody></table>';
            echo '<p>';
            $this->button('link_exact', __('Link all exact matches', 'otium-yachtfolio-sync'));
            echo '</p></form>';
        }

        /* unmatched */
        $unmatched = $this->plugin->linker()->unmatched_posts();
        if ($unmatched !== []) {
            echo '<h3>' . esc_html__('Posts with no counterpart in the feed', 'otium-yachtfolio-sync') . '</h3><ul class="ul-disc">';
            foreach ($unmatched as $row) {
                printf(
                    '<li><a href="%s">%s</a></li>',
                    esc_url((string) get_edit_post_link((int) $row['post_id'])),
                    esc_html((string) $row['post_title'])
                );
            }
            echo '</ul><p class="description">' . esc_html__('These stay unlinked and the sync never touches them.', 'otium-yachtfolio-sync') . '</p>';
        }

        /* taxonomy report */
        echo '<h2 class="oy-section__title" style="margin:var(--oy-8) 0 var(--oy-3)">' . esc_html__('Taxonomy migration report', 'otium-yachtfolio-sync') . '</h2>';
        echo '<table class="oy-table"><thead><tr>'
            . '<th>' . esc_html__('Taxonomy', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Term', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Posts', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Shape', 'otium-yachtfolio-sync') . '</th>'
            . '</tr></thead><tbody>';

        foreach (['yacht-cabins', 'yacht-guests', 'yacht-type', 'yacht-destination'] as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 60]);
            if (is_wp_error($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                $shape = '';
                if ($taxonomy === 'yacht-guests' && preg_match('/^\d+\s*-\s*\d+/', $term->name) === 1) {
                    $shape = __('legacy range — Bricks queries must move to the numeric field', 'otium-yachtfolio-sync');
                } elseif ($taxonomy === 'yacht-cabins' && preg_match('/^\d+$/', $term->name) === 1) {
                    $shape = __('malformed, safe to delete', 'otium-yachtfolio-sync');
                }
                printf(
                    '<tr><td>%s</td><td>%s</td><td>%d</td><td>%s</td></tr>',
                    esc_html($taxonomy),
                    esc_html($term->name),
                    (int) $term->count,
                    esc_html($shape)
                );
            }
        }
        echo '</tbody></table>';

        /* legacy meta */
        echo '<h2 class="oy-section__title" style="margin:var(--oy-8) 0 var(--oy-3)">' . esc_html__('Legacy meta (report only)', 'otium-yachtfolio-sync') . '</h2>';
        echo '<table class="oy-table"><thead><tr><th>' . esc_html__('Meta key', 'otium-yachtfolio-sync')
            . '</th><th>' . esc_html__('Rows', 'otium-yachtfolio-sync') . '</th></tr></thead><tbody>';
        global $wpdb;
        foreach (self::LEGACY_KEYS as $key) {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
                $wpdb->esc_like($key) . '%'
            ));
            printf('<tr><td><code>%s</code></td><td>%d</td></tr>', esc_html($key), $count);
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('Nothing here is deleted automatically: this is editorial data from a previous migration.', 'otium-yachtfolio-sync') . '</p>';

        Menu::close_page();
    }

    public function handle(): void
    {
        Menu::require_cap();
        check_admin_referer('oy_yf_tools');

        $do = isset($_POST['oy_yf_do']) ? sanitize_text_field((string) wp_unslash($_POST['oy_yf_do'])) : '';

        if (str_starts_with($do, 'link:')) {
            [, $yfId, $postId] = array_pad(explode(':', $do), 3, '0');
            try {
                $this->plugin->linker()->confirm((int) $yfId, (int) $postId);
                Menu::flash(sprintf(__('Linked yacht %1$d to post %2$d.', 'otium-yachtfolio-sync'), (int) $yfId, (int) $postId));
            } catch (\Throwable $e) {
                Menu::flash($e->getMessage(), 'error');
            }
            Menu::go(Menu::SLUG_TOOLS);
            return;
        }

        switch ($do) {
            case 'check':
                $failed = [];
                foreach ($this->plugin->client()->ping() as $probe) {
                    if (!$probe['ok']) {
                        $failed[] = $probe['probe'] . ': ' . $probe['detail'];
                    }
                }
                $failed === []
                    ? Menu::flash(__('Every endpoint answered.', 'otium-yachtfolio-sync'))
                    : Menu::flash(implode(' | ', $failed), 'error');
                break;

            case 'reference':
                $this->plugin->reference()->refresh(true);
                Menu::flash(__('Reference cache refreshed.', 'otium-yachtfolio-sync'));
                break;

            case 'recompute':
                $updated = $this->recompute_hashes();
                Menu::flash(sprintf(__('Cleared the stored hash on %d yacht(s); the next pass will re-evaluate them.', 'otium-yachtfolio-sync'), $updated));
                break;

            case 'prune_media':
                $pruned = $this->plugin->mediaLedger()->prune_missing();
                Menu::flash(sprintf(__('%d ledger row(s) whose attachment is gone were removed.', 'otium-yachtfolio-sync'), $pruned));
                break;

            case 'budget':
                $this->plugin->budget()->reset();
                Menu::flash(__('Call budget reset.', 'otium-yachtfolio-sync'));
                break;

            case 'link_exact':
                $linked = 0;
                foreach ($this->plugin->linker()->suggest() as $suggestion) {
                    if ($suggestion['kind'] !== 'exact') {
                        continue;
                    }
                    try {
                        $this->plugin->linker()->confirm((int) $suggestion['yf_id'], (int) $suggestion['post_id']);
                        $linked++;
                    } catch (\Throwable) {
                        // reported per row on the next render
                    }
                }
                Menu::flash(sprintf(__('%d exact match(es) linked.', 'otium-yachtfolio-sync'), $linked));
                break;

            case 'reimport_media':
                $yfId = isset($_POST['yacht']) ? (int) $_POST['yacht'] : 0;
                if ($yfId <= 0) {
                    Menu::flash(__('Enter a feed id first.', 'otium-yachtfolio-sync'), 'error');
                    break;
                }
                $result = $this->plugin->orchestrator()->sync_media($yfId);
                empty($result['ok'])
                    ? Menu::flash((string) ($result['message'] ?? __('media import failed', 'otium-yachtfolio-sync')), 'error')
                    : Menu::flash(sprintf(
                        __('Media: imported %1$d, skipped %2$d, failed %3$d.', 'otium-yachtfolio-sync'),
                        (int) $result['imported'],
                        (int) $result['skipped'],
                        (int) $result['failed']
                    ));
                break;

            case 'export_mapping':
                nocache_headers();
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename=yachtfolio-mapping.json');
                echo (string) wp_json_encode([
                    'fields'    => FieldMap::effective(),
                    'equipment' => EquipmentMap::effective(),
                    'areas'     => AreaAlias::effective(),
                    'types'     => TypeResolver::effective(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;

            default:
                Menu::flash(__('Unknown tool.', 'otium-yachtfolio-sync'), 'error');
        }

        Menu::go(Menu::SLUG_TOOLS);
    }

    private function button(string $value, string $label): void
    {
        printf(
            '<button type="submit" class="oy-btn" name="oy_yf_do" value="%s">%s</button>',
            esc_attr($value),
            esc_html($label)
        );
    }

    private function recompute_hashes(): int
    {
        $map = $this->plugin->map();
        $count = 0;
        foreach ($map->all(['linked' => true, 'limit' => 1000]) as $row) {
            $map->update((int) $row['yf_id'], ['payload_hash' => '', 'brochure_hash' => '']);
            $count++;
        }
        return $count;
    }
}
