<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Admin;

use Otium\Yachtfolio\Activator;
use Otium\Yachtfolio\Mapping\EquipmentMap;
use Otium\Yachtfolio\Plugin;
use Otium\Yachtfolio\Frontend\TemplateRouter;
use Otium\Yachtfolio\Write\EditTracker;
use Otium\Yachtfolio\Write\Ownership;

/**
 * Read-only sync panel on the yacht editor. It writes yf_visible itself and
 * hands "Use the Yachtfolio value" requests to Write\EditTracker.
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
            esc_html__('What you edit here is kept and shown — including a field you empty. Yachtfolio only fills fields nobody has edited.', 'otium-yachtfolio-sync'),
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
        echo '</tbody></table>';

        $this->edited_fields($post->ID);

        echo '<table class="oy-kv"><tbody>';
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

        // The previous state travels with the form: save() writes Visible only
        // when the editor actually flipped it. Writing it on every save would
        // fight WordPress's own Publish button, which marks the yacht visible
        // (Write\Visibility) a moment before this box is saved.
        printf(
            '<input type="hidden" name="oy_yf_visible_was" value="%s">'
            . '<p><label><input type="checkbox" name="oy_yf_visible" value="1" %s> <strong>%s</strong></label><br>'
            . '<span class="description">%s</span></p>',
            $visible ? '1' : '0',
            checked($visible, true, false),
            esc_html__('Visible', 'otium-yachtfolio-sync'),
            esc_html__('Visible means published: switching it on puts the yacht on the website, switching it off moves it back to draft.', 'otium-yachtfolio-sync')
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

        if (isset($_POST['oy_yf_visible_was'])) {
            $was = (string) wp_unslash($_POST['oy_yf_visible_was']) === '1';
            $now = !empty($_POST['oy_yf_visible']);
            if ($was !== $now) {
                update_post_meta($postId, 'yf_visible', $now ? 'true' : 'false');
            }
        }

        // Applied by EditTracker after JetEngine has saved the fields, or the
        // value still in the form would be written straight back.
        $useFeed = array_map('sanitize_key', (array) wp_unslash($_POST['oy_yf_use_feed'] ?? []));
        $useFeed = array_values(array_intersect($useFeed, EditTracker::held($postId)));
        if ($useFeed !== []) {
            EditTracker::request_revert($postId, $useFeed);
        }
    }

    /**
     * The fields this yacht keeps because they were edited in WordPress, each
     * with a way back to the Yachtfolio value. Nothing to decide up front:
     * this only lists what already happened.
     */
    private function edited_fields(int $postId): void
    {
        $held = EditTracker::held($postId);
        if ($held === []) {
            return;
        }
        $newer = EditTracker::feed_changed($postId);
        $labels = self::field_labels();

        echo '<div class="oy-yf-edited"><p><strong>' . esc_html__('Edited in WordPress', 'otium-yachtfolio-sync') . '</strong><br>'
            . '<span class="description">' . esc_html__('These values (an empty field included) are shown on the website exactly as you left them and are not updated from Yachtfolio. To take the Yachtfolio value instead, tick the field and press Update.', 'otium-yachtfolio-sync') . '</span></p><ul>';
        foreach ($held as $field) {
            printf(
                '<li><label><input type="checkbox" name="oy_yf_use_feed[]" value="%s" title="%s"> %s <code>%s</code></label>%s</li>',
                esc_attr($field),
                esc_attr__('Use the Yachtfolio value', 'otium-yachtfolio-sync'),
                esc_html($labels[$field] ?? ucfirst(str_replace(['_', '-'], ' ', $field))),
                esc_html($field),
                in_array($field, $newer, true)
                    ? ' <span class="oy-pill oy-pill--warn">' . esc_html__('Yachtfolio has a newer value', 'otium-yachtfolio-sync') . '</span>'
                    : ''
            );
        }
        echo '</ul></div>';
    }

    /** @return array<string,string> field name => label shown in the yacht editor */
    private static function field_labels(): array
    {
        global $wpdb;
        $labels = [
            'post_title'   => __('Title', 'otium-yachtfolio-sync'),
            'post_content' => __('Description', 'otium-yachtfolio-sync'),
        ];
        $raw = $wpdb->get_var($wpdb->prepare("SELECT meta_fields FROM {$wpdb->prefix}jet_post_types WHERE slug = %s", 'yacht'));
        $fields = is_string($raw) ? maybe_unserialize($raw) : null;
        if (is_string($fields)) {
            $fields = json_decode($fields, true);
        }
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (isset($field['name'], $field['title'])) {
                $labels[(string) $field['name']] = (string) $field['title'];
            }
        }
        return $labels;
    }

    private function kv(string $label, string $value, bool $raw = false): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td>' . ($raw ? $value : esc_html($value)) . '</td></tr>';
    }

    private function legend(): void
    {
        $mapped = Ownership::api_owned_amenity_keys();

        echo '<details class="oy-legend"><summary>' . esc_html__('Which fields does the feed own?', 'otium-yachtfolio-sync') . '</summary>';
        echo '<p class="description">' . esc_html__('Yachtfolio updates a field only while nobody has edited it in WordPress; an edited field is kept until it is emptied or handed back above. On a hand-entered yacht the feed only fills empty fields. Terms are only added on hand-entered yachts and follow the feed on yachts created from it.', 'otium-yachtfolio-sync') . '</p>';
        // esc_html() was previously applied to the joined string, which escaped
        // the <code> separators and printed them as visible &lt;code&gt; text.
        echo '<p><strong>' . esc_html__('Never written on any yacht:', 'otium-yachtfolio-sync') . '</strong> <code>'
            . implode('</code>, <code>', array_map('esc_html', Ownership::ADDON_ONLY))
            . '</code>, ' . esc_html__('plus every amenities_* switcher except', 'otium-yachtfolio-sync') . ' <code>'
            . implode('</code>, <code>', array_map('esc_html', $mapped)) . '</code>.</p>';
        echo '</details>';
    }
}
