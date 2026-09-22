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
    public const SLUG_ALERTS    = 'oy-yf-alerts';

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
    private ?AlertsScreen $alerts = null;
    private ?YachtMetabox $metabox = null;
    private ?Ajax $ajax = null;

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_pages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_filter('admin_body_class', [$this, 'body_class']);

        // Our screens print exactly one kind of notice: the result of the action
        // the admin just took. Everything else — this plugin's own standing
        // warnings included — lives on the Alerts tab, so the top of a working
        // screen is not a noticeboard.
        add_action('in_admin_header', [$this, 'quiet_notices'], 99);
        add_action('admin_notices', [$this, 'print_flash']);

        $this->dashboard()->register();
        $this->settings_screen()->register();
        $this->mapping()->register();
        $this->logs()->register();
        $this->tools()->register();
        $this->alerts()->register();
        $this->metabox()->register();
        $this->ajax()->register();
    }

    /**
     * Strips every queued admin notice from our own screens.
     *
     * Third-party plugins treat the top of every admin page as advertising
     * space, and this plugin used to add its own standing warning to the pile.
     * The result was that the first thing an admin saw on a working screen was
     * a stack of things unrelated to the task. Standing conditions belong on
     * the Alerts tab, which carries a live count in the tab strip; only the
     * outcome of the admin's own last action is still printed inline.
     *
     * Scoped to this plugin's pages: notices elsewhere in wp-admin are
     * untouched.
     */
    public function quiet_notices(): void
    {
        if (!$this->is_our_page()) {
            return;
        }

        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        add_action('admin_notices', [$this, 'print_flash']);
    }

    private function is_our_page(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        return $screen !== null && in_array($screen->id, $this->hooks, true);
    }

    /**
     * Number of things that are actually wrong. Drives the badge on the Alerts
     * tab.
     *
     * Operational failures only. Editorial gaps — a yacht the feed ships with
     * no description or no prices — are real and worth seeing, but they are not
     * failures and there are 114 of them across the catalogue. Counting those
     * put 123 on the badge and buried the handful of yachts that had genuinely
     * broken, which is the exact noise this tab was created to remove. They
     * stay visible in the Attention column and in the Incomplete data filter.
     */
    public function alert_count(): int
    {
        $map = $this->plugin->map();

        return $map->count_detail_unavailable()
            + $map->count_authorisation_lost()
            + $map->count(['status' => YachtMapStore::STATUS_ERROR])
            // Published with the Visible gate closed: not an error, but it is a
            // decision nobody has taken yet, which is what this tab is for.
            + $map->count(['published_not_visible' => true]);
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

        $alerts = $this->alert_count();
        $alertLabel = __('Alerts', 'otium-yachtfolio-sync');
        $alertMenuLabel = $alerts > 0
            ? $alertLabel . ' <span class="update-plugins count-' . $alerts . '"><span class="update-count">'
                . number_format_i18n($alerts) . '</span></span>'
            : $alertLabel;

        $pages = [
            [self::SLUG_DASHBOARD, __('Dashboard', 'otium-yachtfolio-sync'), __('Dashboard', 'otium-yachtfolio-sync'), [$this->dashboard(), 'render']],
            [self::SLUG_YACHTS, __('Yachts', 'otium-yachtfolio-sync'), __('Yachts', 'otium-yachtfolio-sync'), [$this, 'render_yachts']],
            [self::SLUG_ALERTS, $alertLabel, $alertMenuLabel, [$this->alerts(), 'render']],
            [self::SLUG_MAPPING, __('Mapping', 'otium-yachtfolio-sync'), __('Mapping', 'otium-yachtfolio-sync'), [$this->mapping(), 'render']],
            [self::SLUG_LOGS, __('Logs', 'otium-yachtfolio-sync'), __('Logs', 'otium-yachtfolio-sync'), [$this->logs(), 'render']],
            [self::SLUG_TOOLS, __('Tools', 'otium-yachtfolio-sync'), __('Tools', 'otium-yachtfolio-sync'), [$this->tools(), 'render']],
            [self::SLUG_SETTINGS, __('Settings', 'otium-yachtfolio-sync'), __('Settings', 'otium-yachtfolio-sync'), [$this->settings_screen(), 'render']],
        ];

        foreach ($pages as [$slug, $pageTitle, $menuLabel, $callback]) {
            $hook = add_submenu_page(self::SLUG_DASHBOARD, $pageTitle, $menuLabel, $cap, $slug, $callback);
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

    /**
     * WordPress prints admin notices outside `.wrap`, so the design system
     * cannot reach them from the page wrapper alone. This marker on <body>
     * gives the stylesheet a scoped hook for them.
     */
    public function body_class(string $classes): string
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $onOurPage = $screen !== null
            && (in_array($screen->id, $this->hooks, true) || $this->is_yacht_editor());

        return $onOurPage ? trim($classes . ' oy-yf-screen') : $classes;
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

    /**
     * @param string $csv comma separated attention flags
     *
     * The stored flags are sentences ("detail record unavailable"), which is
     * right for a log and far too wide for a table column. The pill carries a
     * short label and the full wording in `title`; the Alerts screen spells out
     * what each one means.
     */
    public static function flag_pills(string $csv): string
    {
        $flags = array_filter(array_map('trim', explode(',', $csv)));
        if ($flags === []) {
            return '<span class="oy-muted">—</span>';
        }

        $short = [
            YachtMapStore::ATTENTION_DETAIL_LOST => __('no detail', 'otium-yachtfolio-sync'),
            YachtMapStore::ATTENTION_AUTH_LOST   => __('no access', 'otium-yachtfolio-sync'),
        ];

        $out = '';
        foreach ($flags as $flag) {
            $label = $short[$flag] ?? str_replace('_', ' ', $flag);
            $out .= sprintf(
                '<span class="oy-pill oy-pill--flag" title="%s">%s</span> ',
                esc_attr($flag),
                esc_html($label)
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

    /**
     * Opens a screen: wrapper, hero and the shared tab strip.
     *
     * Every screen went through `<div class="wrap"><h1>` on its own before,
     * so the six pages shared no navigation at all and `tabs()` was dead code.
     * Screens now call this and `close_page()` instead.
     *
     * Width is not a per-screen decision. Two screens opted into full width and
     * the other five sat 12px narrower, which reads as a rendering fault when
     * you move between tabs. They all use the content width now; the settings
     * form keeps its readable line length by capping its own fields instead.
     *
     * @param string $actionsHtml already-escaped markup for the header right side
     */
    public static function open_page(
        string $current,
        string $title,
        string $subtitle = '',
        string $actionsHtml = ''
    ): void {
        echo '<div class="wrap oy-yf">';
        echo '<div class="oy-hero"><div class="oy-hero__text">';
        printf(
            '<p class="oy-hero__eyebrow"><span class="oy-hero__mark" aria-hidden="true">OY</span>%s</p>',
            esc_html__('Yachtfolio sync', 'otium-yachtfolio-sync')
        );
        printf('<h1 class="oy-hero__title">%s</h1>', esc_html($title));
        if ($subtitle !== '') {
            printf('<p class="oy-hero__sub">%s</p>', esc_html($subtitle));
        }
        echo '</div>';
        if ($actionsHtml !== '') {
            echo '<div class="oy-hero__actions">' . $actionsHtml . '</div>';
        }
        echo '</div>';

        self::tabs($current);
    }

    public static function close_page(): void
    {
        echo '</div>';
    }

    /** Screen tab strip, so every page shares one navigation. */
    public static function tabs(string $current): void
    {
        $tabs = [
            self::SLUG_DASHBOARD => __('Dashboard', 'otium-yachtfolio-sync'),
            self::SLUG_YACHTS    => __('Yachts', 'otium-yachtfolio-sync'),
            self::SLUG_ALERTS    => __('Alerts', 'otium-yachtfolio-sync'),
            self::SLUG_MAPPING   => __('Mapping', 'otium-yachtfolio-sync'),
            self::SLUG_LOGS      => __('Logs', 'otium-yachtfolio-sync'),
            self::SLUG_TOOLS     => __('Tools', 'otium-yachtfolio-sync'),
            self::SLUG_SETTINGS  => __('Settings', 'otium-yachtfolio-sync'),
        ];

        $alerts = Plugin::instance()->menu()->alert_count();

        echo '<nav class="oy-tabs" aria-label="' . esc_attr__('Yachtfolio sections', 'otium-yachtfolio-sync') . '">';
        foreach ($tabs as $slug => $label) {
            $active = $slug === $current;
            $badge  = '';
            if ($slug === self::SLUG_ALERTS && $alerts > 0) {
                $badge = sprintf('<span class="oy-tabs__count">%s</span>', esc_html(number_format_i18n($alerts)));
            }
            printf(
                '<a href="%s" class="oy-tabs__link%s"%s>%s%s</a>',
                esc_url(self::url($slug)),
                $active ? ' is-active' : '',
                $active ? ' aria-current="page"' : '',
                esc_html($label),
                $badge
            );
        }
        echo '</nav>';
    }

    public function alerts(): AlertsScreen
    {
        return $this->alerts ??= new AlertsScreen($this->plugin);
    }
}
