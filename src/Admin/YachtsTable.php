<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Admin;

use Otium\Yachtfolio\Plugin;
use Otium\Yachtfolio\Sync\YachtMapStore;

if (!class_exists('\WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The working screen: every yacht the feed offers (433 rows), server-side
 * paginated. Publishing is available here as an explicit row action and nowhere
 * else.
 */
final class YachtsTable extends \WP_List_Table
{
    private const PER_PAGE = 25;

    public function __construct(private Plugin $plugin)
    {
        parent::__construct([
            'singular' => 'yacht',
            'plural'   => 'yachts',
            'ajax'     => false,
        ]);
    }

    public function render_page(): void
    {
        $this->prepare_items();

        Menu::open_page(
            Menu::SLUG_YACHTS,
            __('Yachts', 'otium-yachtfolio-sync'),
            __('Every yacht the feed offers. Nothing here publishes automatically.', 'otium-yachtfolio-sync'),
            $this->hero_actions(),
            true
        );

        $this->guide();
        $this->views();

        echo '<form method="get" id="oy-yf-yachts-form">';
        printf('<input type="hidden" name="page" value="%s">', esc_attr(Menu::SLUG_YACHTS));
        if (isset($_GET['view'])) {
            printf('<input type="hidden" name="view" value="%s">', esc_attr(sanitize_key((string) $_GET['view'])));
        }
        $this->search_box(__('Search yachts', 'otium-yachtfolio-sync'), 'oy-yf-search');
        $this->display();
        echo '</form>';
        Menu::close_page();
    }

    /**
     * The one action worth promoting from this screen. Everything else is
     * per-row or bulk, and belongs next to the rows it affects.
     */
    private function hero_actions(): string
    {
        return sprintf(
            '<form method="post" action="%s" class="oy-actions">%s'
            . '<input type="hidden" name="action" value="oy_yf_action">'
            . '<input type="hidden" name="oy_yf_return" value="%s">'
            . '<button type="submit" class="oy-btn" name="oy_yf_do" value="dry_run">%s</button>'
            . '<button type="submit" class="oy-btn oy-btn--primary" name="oy_yf_do" value="sync">%s</button>'
            . '</form>',
            esc_url(admin_url('admin-post.php')),
            wp_nonce_field('oy_yf_dashboard', '_wpnonce', true, false),
            esc_attr(Menu::SLUG_YACHTS),
            esc_html__('Dry run', 'otium-yachtfolio-sync'),
            esc_html__('Run sync pass', 'otium-yachtfolio-sync')
        );
    }

    /**
     * Three states decide whether a yacht is imported and published, and their
     * order is not guessable from the column headings alone.
     */
    private function guide(): void
    {
        $steps = [
            __('Select it for sync', 'otium-yachtfolio-sync'),
            __('Set it Visible to allow media and publishing', 'otium-yachtfolio-sync'),
            __('Run a sync pass, then publish when it looks right', 'otium-yachtfolio-sync'),
        ];

        echo '<div class="oy-guide">';
        $i = 0;
        foreach ($steps as $step) {
            if ($i > 0) {
                echo '<span class="oy-guide__arrow" aria-hidden="true">→</span>';
            }
            printf(
                '<span class="oy-guide__step"><span class="oy-guide__n">%d</span>%s</span>',
                ++$i,
                esc_html($step)
            );
        }
        echo '</div>';
    }

    /** @return array<string,string> */
    public function get_columns(): array
    {
        return [
            'cb'         => '<input type="checkbox">',
            'yacht_name' => __('Yacht', 'otium-yachtfolio-sync'),
            'post'       => __('WordPress post', 'otium-yachtfolio-sync'),
            // The two switches are the whole import model: Selected decides
            // whether a pass touches the yacht at all, Visible decides whether
            // its media is fetched and whether it may be published. They sit
            // together, in that order, because that is the order they apply in.
            'selected'   => __('Selected', 'otium-yachtfolio-sync'),
            'visible'    => __('Visible', 'otium-yachtfolio-sync'),
            'status'     => __('Sync', 'otium-yachtfolio-sync'),
            'data'       => __('Data', 'otium-yachtfolio-sync'),
            'modified'   => __('Feed modified', 'otium-yachtfolio-sync'),
            'synced'     => __('Last synced', 'otium-yachtfolio-sync'),
            'attention'  => __('Attention', 'otium-yachtfolio-sync'),
        ];
    }

    /** @return array<string,array{0:string,1:bool}> */
    protected function get_sortable_columns(): array
    {
        return [
            'yacht_name' => ['yacht_name', true],
            'status'     => ['status', false],
            'modified'   => ['last_modified_remote', false],
            'synced'     => ['last_synced_at', false],
        ];
    }

    /** @return array<string,string> */
    protected function get_bulk_actions(): array
    {
        return [
            'select'   => __('Mark selected for sync', 'otium-yachtfolio-sync'),
            'unselect' => __('Remove from sync selection', 'otium-yachtfolio-sync'),
            'show'     => __('Set visible', 'otium-yachtfolio-sync'),
            'hide'     => __('Set hidden', 'otium-yachtfolio-sync'),
            'sync'     => __('Sync now', 'otium-yachtfolio-sync'),
            'dry_run'  => __('Dry run', 'otium-yachtfolio-sync'),
        ];
    }

    protected function get_views(): array
    {
        $map = $this->plugin->map();
        $current = isset($_GET['view']) ? sanitize_key((string) $_GET['view']) : 'all';

        $counts = [
            'all'       => $map->count(),
            'linked'    => $map->count(['linked' => true]),
            'selected'  => $map->count(['selected' => true]),
            'owned'     => $map->count(['owned' => true]),
            'attention' => $map->count(['attention' => true]),
            'error'     => $map->count(['status' => YachtMapStore::STATUS_ERROR]),
            'stale'     => $map->count(['status' => YachtMapStore::STATUS_STALE]),
        ];

        $labels = [
            'all'       => __('All', 'otium-yachtfolio-sync'),
            'linked'    => __('Linked', 'otium-yachtfolio-sync'),
            'selected'  => __('Selected', 'otium-yachtfolio-sync'),
            'owned'     => __('Owned by us', 'otium-yachtfolio-sync'),
            'attention' => __('Needs attention', 'otium-yachtfolio-sync'),
            'error'     => __('Errors', 'otium-yachtfolio-sync'),
            'stale'     => __('Stale', 'otium-yachtfolio-sync'),
        ];

        $views = [];
        foreach ($labels as $view => $label) {
            $views[$view] = sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url(Menu::url(Menu::SLUG_YACHTS, $view === 'all' ? [] : ['view' => $view])),
                $current === $view ? 'current' : '',
                esc_html($label),
                $counts[$view]
            );
        }
        return $views;
    }

    public function prepare_items(): void
    {
        $map = $this->plugin->map();
        $view = isset($_GET['view']) ? sanitize_key((string) $_GET['view']) : 'all';
        $search = isset($_GET['s']) ? sanitize_text_field((string) wp_unslash($_GET['s'])) : '';
        $paged = max(1, (int) ($_GET['paged'] ?? 1));

        $args = [
            'limit'   => self::PER_PAGE,
            'offset'  => ($paged - 1) * self::PER_PAGE,
            'orderby' => isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : 'yacht_name',
            'order'   => isset($_GET['order']) && strtolower((string) $_GET['order']) === 'desc' ? 'DESC' : 'ASC',
        ];

        if ($search !== '') {
            $args['search'] = $search;
        }

        match ($view) {
            'linked'    => $args['linked'] = true,
            'selected'  => $args['selected'] = true,
            'owned'     => $args['owned'] = true,
            'attention' => $args['attention'] = true,
            'error'     => $args['status'] = YachtMapStore::STATUS_ERROR,
            'stale'     => $args['status'] = YachtMapStore::STATUS_STALE,
            default     => null,
        };

        $countArgs = $args;
        unset($countArgs['limit'], $countArgs['offset'], $countArgs['orderby'], $countArgs['order']);

        $this->items = $map->all($args);
        $total = $map->count($countArgs);

        // Visible and Data both read post meta for every row. Without priming,
        // rendering 25 rows costs 25 separate meta queries; one call warms the
        // cache for the whole page.
        $postIds = [];
        foreach ($this->items as $row) {
            $postId = (int) ($row['post_id'] ?? 0);
            if ($postId > 0) {
                $postIds[] = $postId;
            }
        }
        if ($postIds !== []) {
            update_meta_cache('post', $postIds);
        }

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => self::PER_PAGE,
            'total_pages' => (int) ceil($total / self::PER_PAGE),
        ]);
    }

    /** @param array<string,mixed> $item */
    protected function column_cb($item): string
    {
        return sprintf('<input type="checkbox" name="yf_ids[]" value="%d">', (int) $item['yf_id']);
    }

    /** @param array<string,mixed> $item */
    protected function column_yacht_name($item): string
    {
        $yfId = (int) $item['yf_id'];
        $view = $this->view_link($item);
        $raw  = (string) $item['yacht_name'];

        // Truncation is CSS, not a substring: the full name stays in the DOM and
        // in `title`, so search, copy and screen readers still see all of it.
        $label = sprintf('<span class="oy-cell__title oy-trunc" title="%s">%s</span>', esc_attr($raw), esc_html($raw));

        if ($view !== null) {
            // New tab on purpose: this table carries filter and paging state
            // that an admin checking one yacht after another should not lose.
            $label = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url($view[0]),
                $label
            );
        }

        // Feed ID used to own a whole 144px column for a five-digit number.
        // It belongs next to the name it identifies.
        $meta = '#' . $yfId;
        if ((string) $item['registry_port'] !== '') {
            $meta .= ' · ' . (string) $item['registry_port'];
        }

        $name = $label . sprintf(
            '<span class="oy-cell__meta oy-trunc" title="%s">%s</span>',
            esc_attr($meta),
            esc_html($meta)
        );

        if (!empty($item['owned'])) {
            $name .= ' <span class="oy-pill oy-pill--owned">' . esc_html__('owned', 'otium-yachtfolio-sync') . '</span>';
        }

        $actions = [];
        if ($view !== null) {
            // First action: it is the one that was missing entirely, and the
            // name link alone is not an obvious affordance.
            $actions['view'] = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url($view[0]),
                esc_html($view[1])
            );
        }

        $actions += [
            'sync'    => $this->action_link($yfId, 'oy_yf_sync_one', __('Sync', 'otium-yachtfolio-sync')),
            'dry_run' => $this->action_link($yfId, 'oy_yf_dry_run', __('Dry run', 'otium-yachtfolio-sync')),
            'json'    => $this->action_link($yfId, 'oy_yf_show_json', __('Show JSON', 'otium-yachtfolio-sync')),
        ];

        if (!empty($item['post_id'])) {
            $post = get_post((int) $item['post_id']);
            if ($post && $post->post_status === 'publish') {
                $actions['unpublish'] = $this->action_link($yfId, 'oy_yf_unpublish', __('Unpublish', 'otium-yachtfolio-sync'));
            } else {
                $actions['publish'] = $this->action_link($yfId, 'oy_yf_publish', __('Publish', 'otium-yachtfolio-sync'));
            }
            $actions['unlink'] = $this->action_link($yfId, 'oy_yf_unlink', __('Unlink', 'otium-yachtfolio-sync'));
        }

        // "Select/Unselect" used to live here too; the Selected switch states
        // the same thing and can be operated without reading a link label.

        return $name . $this->row_actions($actions);
    }

    /** @param array<string,mixed> $item */
    protected function column_post($item): string
    {
        $postId = (int) ($item['post_id'] ?? 0);
        if ($postId <= 0) {
            return '<span class="oy-muted">' . esc_html__('not linked', 'otium-yachtfolio-sync') . '</span>';
        }
        $post = get_post($postId);
        if (!$post) {
            return '<span class="oy-pill oy-pill--danger">' . esc_html__('missing post', 'otium-yachtfolio-sync') . '</span>';
        }
        return sprintf(
            '<span class="oy-cell__stack"><a href="%s" class="oy-trunc" title="%s">%s</a>'
            . '<span class="oy-pill oy-pill--%s">%s</span></span>',
            esc_url((string) get_edit_post_link($postId)),
            esc_attr($post->post_title),
            esc_html($post->post_title),
            esc_attr($post->post_status),
            esc_html($post->post_status)
        );
    }

    /** @param array<string,mixed> $item */
    protected function column_selected($item): string
    {
        return $this->switch_control(
            (int) $item['yf_id'],
            'oy_yf_toggle_selected',
            !empty($item['selected']),
            __('Selected', 'otium-yachtfolio-sync'),
            __('Skipped', 'otium-yachtfolio-sync'),
            __('Included in every sync pass. Switch off to leave this yacht alone.', 'otium-yachtfolio-sync'),
            __('Ignored by sync passes. Switch on to start importing it.', 'otium-yachtfolio-sync')
        );
    }

    /** @param array<string,mixed> $item */
    protected function column_visible($item): string
    {
        $postId = (int) ($item['post_id'] ?? 0);
        if ($postId <= 0) {
            // Visibility lives on the post; without one there is nothing to set.
            return sprintf(
                '<span class="oy-muted" title="%s">—</span>',
                esc_attr__('Available once the yacht has been imported and linked to a post', 'otium-yachtfolio-sync')
            );
        }

        return $this->switch_control(
            (int) $item['yf_id'],
            'oy_yf_toggle_visible',
            (string) get_post_meta($postId, 'yf_visible', true) === 'true',
            __('Visible', 'otium-yachtfolio-sync'),
            __('Hidden', 'otium-yachtfolio-sync'),
            __('Media is imported and the yacht may be published.', 'otium-yachtfolio-sync'),
            __('No media is imported and the yacht must not be published.', 'otium-yachtfolio-sync')
        );
    }

    /**
     * A real switch rather than a button labelled with its own state: "Visible"
     * on a button reads as a description of the row, not as something you can
     * change. `role="switch"` plus `aria-checked` gives assistive technology the
     * same two-state meaning the graphic carries.
     */
    private function switch_control(
        int $yfId,
        string $action,
        bool $on,
        string $onLabel,
        string $offLabel,
        string $onHint,
        string $offHint
    ): string {
        return sprintf(
            '<button type="button" role="switch" aria-checked="%s" class="oy-switch oy-yf-toggle"'
            . ' data-action="%s" data-yacht="%d" title="%s"'
            // Both labels travel with the control so the script can keep the
            // wording in step with the graphic while the request is in flight.
            . ' data-label-on="%s" data-label-off="%s">'
            . '<span class="oy-switch__track" aria-hidden="true"><span class="oy-switch__thumb"></span></span>'
            . '<span class="oy-switch__label">%s</span></button>',
            $on ? 'true' : 'false',
            esc_attr($action),
            $yfId,
            esc_attr($on ? $onHint : $offHint),
            esc_attr($onLabel),
            esc_attr($offLabel),
            esc_html($on ? $onLabel : $offLabel)
        );
    }

    /** @param array<string,mixed> $item */
    protected function column_status($item): string
    {
        $html  = Menu::status_pill((string) $item['status']);
        $error = trim((string) ($item['last_error'] ?? ''));

        if ($error !== '') {
            // Previously printed up to 120 characters raw, which wrapped to six
            // lines and set the height of the whole row. Two clamped lines, full
            // text in the tooltip.
            $html .= sprintf(
                '<span class="oy-cell__meta oy-clamp" title="%s">%s</span>',
                esc_attr($error),
                esc_html($error)
            );
        }

        return $html;
    }

    /**
     * How much of a yacht is actually populated.
     *
     * The importer already records presence markers on the post — it does not
     * need to be recomputed or guessed here. A five-segment bar is used instead
     * of a text chip because this column is read down 25 rows at a time: "which
     * yachts are empty" is a shape question, and a bar answers it without
     * reading a word.
     *
     * @param array<string,mixed> $item
     */
    protected function column_data($item): string
    {
        $postId = (int) ($item['post_id'] ?? 0);
        if ($postId <= 0) {
            return sprintf(
                '<span class="oy-muted" title="%s">%s</span>',
                esc_attr__('Not imported yet, so there is nothing to measure', 'otium-yachtfolio-sync'),
                esc_html__('not imported', 'otium-yachtfolio-sync')
            );
        }

        $parts = [
            'yf_has_rates'     => __('Rates', 'otium-yachtfolio-sync'),
            'yf_has_amenities' => __('Amenities', 'otium-yachtfolio-sync'),
            'yf_has_crew'      => __('Crew', 'otium-yachtfolio-sync'),
            'yf_has_toys'      => __('Toys', 'otium-yachtfolio-sync'),
            'yf_has_gallery'   => __('Gallery', 'otium-yachtfolio-sync'),
        ];

        $have = [];
        $missing = [];
        $bar = '';

        foreach ($parts as $key => $label) {
            // The marker is the count when present and an empty string when not.
            $on = (string) get_post_meta($postId, $key, true) !== '';
            $on ? $have[] = $label : $missing[] = $label;
            $bar .= sprintf('<span class="oy-meter__seg%s"></span>', $on ? ' is-on' : '');
        }

        $filled = count($have);
        $total  = count($parts);

        $tone = $filled === $total ? ' oy-meter--full' : ($filled === 0 ? ' oy-meter--empty' : '');

        $tooltip = $filled === $total
            ? __('Complete: rates, amenities, crew, toys and gallery are all present.', 'otium-yachtfolio-sync')
            : sprintf(
                /* translators: %s: comma separated list of missing sections */
                __('Missing: %s', 'otium-yachtfolio-sync'),
                implode(', ', $missing)
            );

        // Media progress is part of the same question, so it rides along in the
        // label rather than occupying a column of its own.
        $media = (int) $item['media_total'] > 0
            ? sprintf(' · %d/%d img', (int) $item['image_count'], (int) $item['media_total'])
            : '';

        return sprintf(
            '<span class="oy-meter%s" title="%s"><span class="oy-meter__bar">%s</span>'
            . '<span class="oy-meter__label">%d/%d%s</span></span>',
            $tone,
            esc_attr($tooltip),
            $bar,
            $filled,
            $total,
            esc_html($media)
        );
    }

    /** @param array<string,mixed> $item */
    protected function column_modified($item): string
    {
        return $this->timestamp((string) $item['last_modified_remote']);
    }

    /** @param array<string,mixed> $item */
    protected function column_synced($item): string
    {
        return $this->timestamp($item['last_synced_at'] !== null ? (string) $item['last_synced_at'] : '');
    }

    /**
     * The feed sends ISO 8601 with milliseconds and a Z suffix, which rendered
     * raw and wrapped onto two lines. Both formats land here.
     */
    private function timestamp(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || str_starts_with($raw, '0000')) {
            return '<span class="oy-muted">—</span>';
        }

        $ts = strtotime(str_contains($raw, 'T') ? $raw : $raw . ' UTC');
        if ($ts === false) {
            return sprintf('<span class="oy-trunc" title="%s">%s</span>', esc_attr($raw), esc_html($raw));
        }

        return sprintf(
            '<span class="oy-nowrap" title="%s">%s</span>',
            esc_attr($raw),
            esc_html((string) wp_date('Y-m-d H:i', $ts))
        );
    }

    /** @param array<string,mixed> $item */
    protected function column_attention($item): string
    {
        return Menu::flag_pills((string) $item['attention']);
    }

    /**
     * @param array<string,mixed> $item
     * @param string $column_name
     */
    protected function column_default($item, $column_name): string
    {
        return esc_html((string) ($item[$column_name] ?? ''));
    }

    private function action_link(int $yfId, string $action, string $label): string
    {
        return sprintf(
            '<a href="#" class="oy-yf-action" data-action="%s" data-yacht="%d">%s</a>',
            esc_attr($action),
            $yfId,
            esc_html($label)
        );
    }

    /**
     * Where to send an admin who wants to see the yacht page itself.
     *
     * The Post column already links to the editor; nothing linked to the
     * rendered page, so there was no way to check a yacht's layout from here.
     *
     * A published yacht gets its permalink. Anything else — draft, pending,
     * private — gets WordPress's preview URL, which is the only link that
     * renders an unpublished post. Feed-created yachts arrive as drafts, so the
     * draft case is the normal one, not the exception.
     *
     * @return array{0:string,1:string}|null [url, label], or null when there is
     *                                       nothing to show
     */
    private function view_link(array $item): ?array
    {
        $postId = (int) ($item['post_id'] ?? 0);
        if ($postId <= 0) {
            return null;
        }

        $post = get_post($postId);
        if (!$post || $post->post_type !== 'yacht') {
            return null;
        }

        if ($post->post_status === 'publish') {
            $url = (string) get_permalink($post);
            return $url === '' ? null : [$url, __('View', 'otium-yachtfolio-sync')];
        }

        // Preview needs edit rights: the page is not public yet.
        if (!current_user_can('edit_post', $postId)) {
            return null;
        }

        $url = (string) get_preview_post_link($post);
        return $url === '' ? null : [$url, __('Preview', 'otium-yachtfolio-sync')];
    }
}
