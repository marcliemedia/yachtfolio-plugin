<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Admin;

use Otium\Yachtfolio\Activator;
use Otium\Yachtfolio\Plugin;
use Otium\Yachtfolio\Sync\YachtMapStore;

/**
 * Router for every admin surface.
 *
 * Screens are instantiated eagerly because their constructors do nothing but
 * hold the container: each one registers its own admin_post handler, and the
 * router must not have to know which page is being rendered to do that.
 */
final class Menu
{
    public const SLUG_DASHBOARD = 'oy-yf-dashboard';
    public const SLUG_YACHTS    = 'oy-yf-yachts';
    public const SLUG_MAPPING   = 'oy-yf-mapping';
    public const SLUG_LOGS      = 'oy-yf-logs';
    public const SLUG_TOOLS     = 'oy-yf-tools';
    public const SLUG_SETTINGS  = 'oy-yf-settings';

    /** Single nonce action shared by every AJAX endpoint. */
    public const NONCE_AJAX = 'oy_yf_admin';

    private const FLASH_PREFIX = 'oy_yf_flash_';

    /** @var array<int,string> hook suffixes of our own pages */
    private array $hooks = [];

    private ?Dashboard $dashboard = null;
    private ?SettingsScreen $settingsScreen = null;
    private ?MappingScreen $mapping = null;
    private ?LogsScreen $logs = null;
    private ?ToolsScreen $tools = null;
    private ?YachtMetabox $metabox = null;
    private ?Ajax $ajax = null;

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_pages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_notices', [$this, 'print_flash']);
        add_action('admin_notices', [$this, 'print_attention_notice']);

        $this->dashboard()->register();
        $this->settings_screen()->register();
        $this->mapping()->register();
        $this->logs()->register();
        $this->tools()->register();
        $this->metabox()->register();
        $this->ajax()->register();
    }

    /**
     * Yachtfolio withdraws a yacht's structured detail record silently: HTTP
     * 200, no errors, zero rows. Nobody opens a report to discover that, so it
     * is pushed into wp-admin instead. Data is kept and nothing is unpublished;
     * this notice is the only thing that changes.
     */
    public function print_attention_notice(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            return;
        }

        $map = $this->plugin->map();
        $detail = $map->count_detail_unavailable();
        $lost   = $map->count_authorisation_lost();

        if ($detail === 0 && $lost === 0) {
            return;
        }

        $lines = [];
        if ($detail > 0) {
            $rows = [];
            foreach ($map->all(['attention' => true, 'limit' => 20]) as $row) {
                $flags = \Otium\Yachtfolio\Sync\YachtMapStore::split_flags((string) $row['attention']);
                if (!in_array(\Otium\Yachtfolio\Sync\YachtMapStore::ATTENTION_DETAIL_LOST, $flags, true)) {
                    continue;
                }
                $since = (string) ($row['authorisation_last_ok_at'] ?? '');
                $rows[] = esc_html((string) $row['yacht_name']) . ($since !== '' ? ' <em>(' . esc_html($since) . ')</em>' : '');
            }
            $lines[] = sprintf(
                /* translators: 1: count, 2: yacht list */
                _n(
                    '%1$d yacht has no structured detail record from Yachtfolio, so its rates, amenities and cruising areas cannot be refreshed: %2$s',
                    '%1$d yachts have no structured detail record from Yachtfolio, so their rates, amenities and cruising areas cannot be refreshed: %2$s',
                    $detail,
                    'otium-yachtfolio-sync'
                ),
                $detail,
                implode(', ', $rows)
            );
        }
        if ($lost > 0) {
            $lines[] = sprintf(
                _n(
                    '%d yacht returns no data at all while still being listed. Its content is untouched and it has NOT been unpublished.',
                    '%d yachts return no data at all while still being listed. Their content is untouched and they have NOT been unpublished.',
                    $lost,
                    'otium-yachtfolio-sync'
                ),
                $lost
            );
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong></p><p>%s</p><p><a href="%s">%s</a></p></div>',
            esc_html__('Yachtfolio needs attention', 'otium-yachtfolio-sync'),
            implode('</p><p>', $lines),
            esc_url(admin_url('admin.php?page=' . self::SLUG_YACHTS . '&attention=1')),
            esc_html__('Review the affected yachts', 'otium-yachtfolio-sync')
        );
    }

    public function add_pages(): void
    {
        $cap = Activator::CAPABILITY;

        $this->hooks[] = add_menu_page(
            __('Yachtfolio', 'otium-yachtfolio-sync'),
            __('Yachtfolio', 'otium-yachtfolio-sync'),
            $cap,
            self::SLUG_DASHBOARD,
            [$this->dashboard(), 'render'],
            'dashicons-sos',
            58
        );

        $pages = [
            [self::SLUG_DASHBOARD, __('Dashboard', 'otium-yachtfolio-sync'), [$this->dashboard(), 'render']],
            [self::SLUG_YACHTS, __('Yachts', 'otium-yachtfolio-sync'), [$this, 'render_yachts']],
            [self::SLUG_MAPPING, __('Mapping', 'otium-yachtfolio-sync'), [$this->mapping(), 'render']],
            [self::SLUG_LOGS, __('Logs', 'otium-yachtfolio-sync'), [$this->logs(), 'render']],
            [self::SLUG_TOOLS, __('Tools', 'otium-yachtfolio-sync'), [$this->tools(), 'render']],
            [self::SLUG_SETTINGS, __('Settings', 'otium-yachtfolio-sync'), [$this->settings_screen(), 'render']],
        ];

        foreach ($pages as [$slug, $label, $callback]) {
            $hook = add_submenu_page(self::SLUG_DASHBOARD, $label, $label, $cap, $slug, $callback);
            if (is_string($hook) && $hook !== '') {
                $this->hooks[] = $hook;
            }
        }
    }

    /**
     * The Yachts screen owns a WP_List_Table, which must be built after
     * add_submenu_page so screen options exist.
     */
    public function render_yachts(): void
    {
        self::require_cap();
        (new YachtsTable($this->plugin))->render_page();
    }

    public function enqueue(string $hook): void
    {
        if (!in_array($hook, $this->hooks, true) && !$this->is_yacht_editor()) {
            return;
        }

        wp_enqueue_style(
            'oy-yf-admin',
            $this->plugin->url() . 'assets/admin.css',
            [],
            OY_YF_VERSION
        );

        wp_enqueue_script(
            'oy-yf-admin',
            $this->plugin->url() . 'assets/admin.js',
            [],
            OY_YF_VERSION,
            true
        );

        wp_localize_script('oy-yf-admin', 'oyYf', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_AJAX),
            'i18n'    => [
                'working'    => __('Working…', 'otium-yachtfolio-sync'),
                'failed'     => __('Request failed.', 'otium-yachtfolio-sync'),
                'close'      => __('Close', 'otium-yachtfolio-sync'),
                'noChanges'  => __('No field would change.', 'otium-yachtfolio-sync'),
                'diffTitle'  => __('Dry run diff', 'otium-yachtfolio-sync'),
                'jsonTitle'  => __('Stored payload (passkey scrubbed)', 'otium-yachtfolio-sync'),
                'colField'   => __('Field', 'otium-yachtfolio-sync'),
                'colOwner'   => __('Owner', 'otium-yachtfolio-sync'),
                'colOld'     => __('Current', 'otium-yachtfolio-sync'),
                'colNew'     => __('From feed', 'otium-yachtfolio-sync'),
                'colNote'    => __('Note', 'otium-yachtfolio-sync'),
                'skipped'    => __('skipped', 'otium-yachtfolio-sync'),
                'pickAction' => __('Choose a bulk action first.', 'otium-yachtfolio-sync'),
                'pickRows'   => __('Select at least one yacht.', 'otium-yachtfolio-sync'),
                'confirmRun' => __('Queue a sync pass now?', 'otium-yachtfolio-sync'),
            ],
        ]);
    }

    private function is_yacht_editor(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        return $screen !== null && $screen->base === 'post' && $screen->post_type === 'yacht';
    }

    /* ------------------------------------------------------------------ *
     * Screens
     * ------------------------------------------------------------------ */

    public function dashboard(): Dashboard
    {
        return $this->dashboard ??= new Dashboard($this->plugin);
    }

    public function settings_screen(): SettingsScreen
    {
        return $this->settingsScreen ??= new SettingsScreen($this->plugin);
    }

    public function mapping(): MappingScreen
    {
        return $this->mapping ??= new MappingScreen($this->plugin);
    }

    public function logs(): LogsScreen
    {
        return $this->logs ??= new LogsScreen($this->plugin);
    }

    public function tools(): ToolsScreen
    {
        return $this->tools ??= new ToolsScreen($this->plugin);
    }

    public function metabox(): YachtMetabox
    {
        return $this->metabox ??= new YachtMetabox($this->plugin);
    }

    public function ajax(): Ajax
    {
        return $this->ajax ??= new Ajax($this->plugin);
    }

    /* ------------------------------------------------------------------ *
     * Shared helpers
     * ------------------------------------------------------------------ */

    /** @param array<string,string|int> $args */
    public static function url(string $slug, array $args = []): string
    {
        return add_query_arg(array_merge(['page' => $slug], $args), admin_url('admin.php'));
    }

    public static function require_cap(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to manage the Yachtfolio sync.', 'otium-yachtfolio-sync'), 403);
        }
    }

    /** Queue a one-shot notice for the current user, survives the redirect. */
    public static function flash(string $text, string $type = 'success'): void
    {
        set_transient(self::FLASH_PREFIX . get_current_user_id(), ['text' => $text, 'type' => $type], 120);
    }

    public function print_flash(): void
    {
        $key   = self::FLASH_PREFIX . get_current_user_id();
        $flash = get_transient($key);
        if (!is_array($flash) || !isset($flash['text'])) {
            return;
        }
        delete_transient($key);

        $type = in_array($flash['type'] ?? '', ['success', 'error', 'warning', 'info'], true)
            ? (string) $flash['type']
            : 'info';

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html((string) $flash['text'])
        );
    }

    /** Redirect back to one of our pages after a POST. Never returns. */
    public static function go(string $slug, array $args = []): void
    {
        wp_safe_redirect(self::url($slug, $args));
        exit;
    }

    public static function status_pill(string $status): string
    {
        $labels = [
            YachtMapStore::STATUS_UNLINKED => __('unlinked', 'otium-yachtfolio-sync'),
            YachtMapStore::STATUS_SELECTED => __('selected', 'otium-yachtfolio-sync'),
            YachtMapStore::STATUS_PENDING  => __('pending', 'otium-yachtfolio-sync'),
            YachtMapStore::STATUS_SYNCED   => __('synced', 'otium-yachtfolio-sync'),
            YachtMapStore::STATUS_ERROR    => __('error', 'otium-yachtfolio-sync'),
            YachtMapStore::STATUS_STALE    => __('stale', 'otium-yachtfolio-sync'),
        ];

        $label = $labels[$status] ?? $status;

        return sprintf(
            '<span class="oy-pill oy-pill--%s">%s</span>',
            esc_attr(preg_replace('/[^a-z0-9_-]/', '', strtolower($status)) ?? 'unknown'),
            esc_html($label)
        );
    }

    /** @param string $csv comma separated attention flags */
    public static function flag_pills(string $csv): string
    {
        $flags = array_filter(array_map('trim', explode(',', $csv)));
        if ($flags === []) {
            return '<span class="oy-muted">—</span>';
        }

        $out = '';
        foreach ($flags as $flag) {
            $out .= sprintf(
                '<span class="oy-pill oy-pill--flag" title="%s">%s</span> ',
                esc_attr($flag),
                esc_html(str_replace('_', ' ', $flag))
            );
        }

        return trim($out);
    }

    /** Local-time rendering of a UTC MySQL timestamp. */
    public static function stamp(?string $utc): string
    {
        if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) {
            return '—';
        }
        $ts = strtotime($utc . ' UTC');
        if ($ts === false) {
            return $utc;
        }
        return wp_date('Y-m-d H:i', $ts) ?: $utc;
    }

    public static function duration(?string $from, ?string $to): string
    {
        if (!$from) {
            return '—';
        }
        $start = strtotime($from . ' UTC');
        $end   = $to ? strtotime($to . ' UTC') : time();
        if ($start === false || $end === false || $end < $start) {
            return '—';
        }
        $seconds = $end - $start;
        if ($seconds < 60) {
            return sprintf(
                /* translators: %d: seconds */
                _n('%d second', '%d seconds', $seconds, 'otium-yachtfolio-sync'),
                $seconds
            );
        }
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public static function countdown(int $seconds): string
    {
        if ($seconds <= 0) {
            return __('now', 'otium-yachtfolio-sync');
        }
        if ($seconds < 60) {
            return sprintf(
                /* translators: %d: seconds */
                __('%ds', 'otium-yachtfolio-sync'),
                $seconds
            );
        }
        return sprintf(
            /* translators: 1: minutes, 2: seconds */
            __('%1$dm %2$ds', 'otium-yachtfolio-sync'),
            intdiv($seconds, 60),
            $seconds % 60
        );
    }

    /** Screen tab strip, so every page shares one navigation. */
    public static function tabs(string $current): void
    {
        $tabs = [
            self::SLUG_DASHBOARD => __('Dashboard', 'otium-yachtfolio-sync'),
            self::SLUG_YACHTS    => __('Yachts', 'otium-yachtfolio-sync'),
            self::SLUG_MAPPING   => __('Mapping', 'otium-yachtfolio-sync'),
            self::SLUG_LOGS      => __('Logs', 'otium-yachtfolio-sync'),
            self::SLUG_TOOLS     => __('Tools', 'otium-yachtfolio-sync'),
            self::SLUG_SETTINGS  => __('Settings', 'otium-yachtfolio-sync'),
        ];

        echo '<nav class="nav-tab-wrapper oy-tabs">';
        foreach ($tabs as $slug => $label) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url(self::url($slug)),
                $slug === $current ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</nav>';
    }
}
