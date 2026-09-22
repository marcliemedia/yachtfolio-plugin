<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Admin;

use Otium\Yachtfolio\Activator;
use Otium\Yachtfolio\Mapping\EquipmentMap;
use Otium\Yachtfolio\Plugin;
use Otium\Yachtfolio\Frontend\TemplateRouter;
use Otium\Yachtfolio\Write\Ownership;

/**
 * Read-only sync panel on the yacht editor. The only two values it writes are
 * yf_visible and yf_locked_fields — both admin-owned.
 */
final class YachtMetabox
{
    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('add_meta_boxes_yacht', [$this, 'add']);
        add_action('save_post_yacht', [$this, 'save'], 10, 2);
    }

    public function add(): void
    {
        add_meta_box(
            'oy-yf-sync',
            __('Yachtfolio sync', 'otium-yachtfolio-sync'),
            [$this, 'render'],
            'yacht',
            'side',
            'high'
        );
    }

    public function render(\WP_Post $post): void
    {
        $map = $this->plugin->map();
        $row = $map->by_post($post->ID);

        wp_nonce_field('oy_yf_metabox_' . $post->ID, 'oy_yf_metabox_nonce');

        if ($row === null) {
            echo '<div class="oy-yf-metabox">';
            echo '<p>' . esc_html__('This yacht is not linked to a Yachtfolio row, so the sync never touches it.', 'otium-yachtfolio-sync') . '</p>';
            printf(
                '<p><a class="oy-btn" href="%s">%s</a></p>',
                esc_url(Menu::url(Menu::SLUG_TOOLS)),
                esc_html__('Link it in Tools', 'otium-yachtfolio-sync')
            );
            echo '</div>';
            return;
        }

        $yfId = (int) $row['yf_id'];
        $visible = (string) get_post_meta($post->ID, 'yf_visible', true) === 'true';

        $origin  = Ownership::origin_of($post->ID);
        $manual  = $origin === Ownership::ORIGIN_MANUAL;
        $mode    = (string) $this->plugin->settings()->get('write_mode', 'fill_empty_only');
        $protect = $manual && $mode !== 'feed_authoritative';

        echo '<div class="oy-yf-metabox">';
        echo '<table class="oy-kv"><tbody>';
        $this->kv(__('Feed ID', 'otium-yachtfolio-sync'), (string) $yfId . ($row['owned'] ? ' (' . __('owned', 'otium-yachtfolio-sync') . ')' : ''));
        $this->kv(
            __('This yacht was', 'otium-yachtfolio-sync'),
            $manual
                ? '<strong>' . esc_html__('typed in by hand', 'otium-yachtfolio-sync') . '</strong>'
                : esc_html__('created from the feed', 'otium-yachtfolio-sync'),
            true
        );
        $this->kv(
            __('Overwrite rule', 'otium-yachtfolio-sync'),
            $protect
                ? '<span style="color:#046b3f">' . esc_html__('protected — only empty fields get filled', 'otium-yachtfolio-sync') . '</span>'
                : '<span style="color:#8a2b06">' . esc_html__('the feed rewrites mapped fields', 'otium-yachtfolio-sync') . '</span>',
            true
        );
        // The template routing lives in PHP, so it is invisible in the Bricks
        // Conditions panel. Say it out loud here or nobody will ever find it.
        $routed = TemplateRouter::resolve_for($post->ID, $this->plugin->settings());
        $this->kv(
            __('Rendered by', 'otium-yachtfolio-sync'),
            $routed !== null
                ? sprintf(
                    '<a href="%s">%s</a> <em>(%s)</em>',
                    esc_url((string) get_edit_post_link($routed)),
                    esc_html((string) get_the_title($routed)),
                    esc_html__('feed template', 'otium-yachtfolio-sync')
                )
                : '<em>' . esc_html__('the normal Yacht single template', 'otium-yachtfolio-sync') . '</em>',
            true
        );

        $detailPresent = (string) get_post_meta($post->ID, 'yf_detail_present', true);
        if ($detailPresent === '0') {
            $this->kv(
                __('Detail record', 'otium-yachtfolio-sync'),
                '<span style="color:#8a2b06">' . esc_html__('unavailable — rates, amenities and cruising areas cannot be refreshed', 'otium-yachtfolio-sync') . '</span>',
                true
            );
        }
        $protectedList = (string) get_post_meta($post->ID, 'yf_protected_fields', true);
        if ($protectedList !== '') {
            $keys = array_filter(array_map('trim', explode(',', $protectedList)));
            $this->kv(
                __('Kept from the feed', 'otium-yachtfolio-sync'),
                '<code>' . implode('</code>, <code>', array_map('esc_html', $keys)) . '</code>',
                true
            );
        }
        $this->kv(__('Sync status', 'otium-yachtfolio-sync'), Menu::status_pill((string) $row['status']), true);
        $this->kv(__('Feed modified', 'otium-yachtfolio-sync'), (string) $row['last_modified_remote']);
        $this->kv(__('Last synced', 'otium-yachtfolio-sync'), Menu::stamp($row['last_synced_at'] !== null ? (string) $row['last_synced_at'] : null));
        $this->kv(__('Content hash', 'otium-yachtfolio-sync'), substr((string) $row['brochure_hash'], 0, 12));
        $this->kv(__('Images', 'otium-yachtfolio-sync'), sprintf('%d / %d', (int) $row['image_count'], (int) $row['media_total']));
        if ((string) $row['attention'] !== '') {
            $this->kv(__('Attention', 'otium-yachtfolio-sync'), Menu::flag_pills((string) $row['attention']), true);
        }
        if ((string) ($row['last_error'] ?? '') !== '') {
            $this->kv(__('Last error', 'otium-yachtfolio-sync'), (string) $row['last_error']);
        }
        echo '</tbody></table>';

        printf(
            '<p><label><input type="checkbox" name="oy_yf_visible" value="1" %s> <strong>%s</strong></label><br>'
            . '<span class="description">%s</span></p>',
            checked($visible, true, false),
            esc_html__('Visible', 'otium-yachtfolio-sync'),
            esc_html__('Required before this yacht may be published, and before its media is imported.', 'otium-yachtfolio-sync')
        );

        printf(
            '<p><label for="oy_yf_locked"><strong>%s</strong></label>'
            . '<textarea id="oy_yf_locked" name="oy_yf_locked_fields" rows="3" class="widefat" placeholder="%s">%s</textarea>'
            . '<span class="description">%s</span></p>',
            esc_html__('Frozen fields', 'otium-yachtfolio-sync'),
            esc_attr__('one meta key per line', 'otium-yachtfolio-sync'),
            esc_textarea((string) get_post_meta($post->ID, 'yf_locked_fields', true)),
            esc_html__('Warning: every key listed here stops the feed from updating that field on this yacht. Use it only as a deliberate exception.', 'otium-yachtfolio-sync')
        );

        printf(
            '<div class="oy-actions"><button type="button" class="oy-btn oy-yf-action" data-action="oy_yf_dry_run" data-yacht="%1$d">%2$s</button>'
            . '<button type="button" class="oy-btn oy-btn--primary oy-yf-action" data-action="oy_yf_sync_one" data-yacht="%1$d">%3$s</button></div>',
            $yfId,
            esc_html__('Dry run', 'otium-yachtfolio-sync'),
            esc_html__('Sync now', 'otium-yachtfolio-sync')
        );

        $this->legend();
        echo '</div>';
    }

    public function save(int $postId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can(Activator::CAPABILITY) || !current_user_can('edit_post', $postId)) {
            return;
        }
        $nonce = isset($_POST['oy_yf_metabox_nonce']) ? (string) wp_unslash($_POST['oy_yf_metabox_nonce']) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'oy_yf_metabox_' . $postId)) {
            return;
        }

        update_post_meta($postId, 'yf_visible', empty($_POST['oy_yf_visible']) ? 'false' : 'true');

        $locked = isset($_POST['oy_yf_locked_fields'])
            ? sanitize_textarea_field((string) wp_unslash($_POST['oy_yf_locked_fields']))
            : '';
        if (trim($locked) === '') {
            delete_post_meta($postId, 'yf_locked_fields');
        } else {
            update_post_meta($postId, 'yf_locked_fields', $locked);
        }
    }

    private function kv(string $label, string $value, bool $raw = false): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td>' . ($raw ? $value : esc_html($value)) . '</td></tr>';
    }

    private function legend(): void
    {
        $mapped = Ownership::api_owned_amenity_keys();

        echo '<details class="oy-legend"><summary>' . esc_html__('Which fields does the feed own?', 'otium-yachtfolio-sync') . '</summary>';
        echo '<p class="description">' . esc_html__('On a hand-entered yacht the feed only fills fields that are empty — an existing value is never replaced, and terms are only added. On a yacht created from the feed, mapped fields are refreshed on every sync.', 'otium-yachtfolio-sync') . '</p>';
        // esc_html() was previously applied to the joined string, which escaped
        // the <code> separators and printed them as visible &lt;code&gt; text.
        echo '<p><strong>' . esc_html__('Never written on any yacht:', 'otium-yachtfolio-sync') . '</strong> <code>'
            . implode('</code>, <code>', array_map('esc_html', Ownership::ADDON_ONLY))
            . '</code>, ' . esc_html__('plus every amenities_* switcher except', 'otium-yachtfolio-sync') . ' <code>'
            . implode('</code>, <code>', array_map('esc_html', $mapped)) . '</code>.</p>';
        echo '</details>';
    }
}
