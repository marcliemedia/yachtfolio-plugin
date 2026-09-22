<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Admin;

use Otium\Yachtfolio\Plugin;
use Otium\Yachtfolio\Sync\YachtMapStore;

final class Dashboard
{
    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_post_oy_yf_action', [$this, 'handle_action']);
    }

    public function render(): void
    {
        Menu::require_cap();

        $settings = $this->plugin->settings();
        $budget   = $this->plugin->budget();
        $map      = $this->plugin->map();
        $counts   = $map->status_counts();
        $run      = $this->plugin->runs()->latest();
        $next     = $this->plugin->jobs()->next_scheduled();
        $reference = $this->plugin->reference();

        $total    = array_sum($counts);
        $linked   = $map->count(['linked' => true]);
        $selected = $map->count(['selected' => true]);
        $attention = $map->count(['attention' => true]);

        Menu::open_page(
            Menu::SLUG_DASHBOARD,
            __('Dashboard', 'otium-yachtfolio-sync'),
            __('One-way sync from the Yachtfolio Public API. Nothing is ever written back, and nothing is published without a human.', 'otium-yachtfolio-sync')
        );

        /* headline numbers */
        echo '<div class="oy-grid">';
        $this->stat(__('Feed rows', 'otium-yachtfolio-sync'), (string) $total, __('yachts offered by the API', 'otium-yachtfolio-sync'));
        $this->stat(__('Linked', 'otium-yachtfolio-sync'), (string) $linked, __('matched to a yacht post', 'otium-yachtfolio-sync'), 'accent');
        $this->stat(__('Selected', 'otium-yachtfolio-sync'), (string) $selected, __('queued for detail import', 'otium-yachtfolio-sync'));
        $this->stat(
            __('Needs attention', 'otium-yachtfolio-sync'),
            (string) $attention,
            __('authorisation or detail lost', 'otium-yachtfolio-sync'),
            $attention > 0 ? 'warn' : ''
        );
        echo '</div>';

        /* actions */
        echo '<section class="oy-section">';
        echo '<div class="oy-section__head"><h2 class="oy-section__title">' . esc_html__('Actions', 'otium-yachtfolio-sync') . '</h2></div>';
        echo '<p class="description" style="margin-bottom:var(--oy-3)">' . esc_html__('An import never publishes anything: new yachts arrive as drafts and publishing stays a human decision.', 'otium-yachtfolio-sync') . '</p>';

        $this->action_form([
            'sync'      => __('Sync now', 'otium-yachtfolio-sync'),
            'dry_run'   => __('Dry run', 'otium-yachtfolio-sync'),
            'reference' => __('Refresh reference cache', 'otium-yachtfolio-sync'),
            'check'     => __('Check connection', 'otium-yachtfolio-sync'),
        ]);
        echo '</section>';

        /* detail cards */
        echo '<section class="oy-section">';
        echo '<div class="oy-section__head"><h2 class="oy-section__title">' . esc_html__('Status', 'otium-yachtfolio-sync') . '</h2></div>';
        echo '<div class="oy-grid">';

        $this->card(__('Connection', 'otium-yachtfolio-sync'), [
            __('Mode', 'otium-yachtfolio-sync')    => strtoupper($settings->mode()),
            __('Passkey', 'otium-yachtfolio-sync') => ($settings->masked_passkey() ?: __('(none)', 'otium-yachtfolio-sync'))
                . ' — ' . $settings->passkey_source(),
            __('Reference cache', 'otium-yachtfolio-sync') => $reference->updated_at() > 0
                ? sprintf(__('%s ago', 'otium-yachtfolio-sync'), human_time_diff($reference->updated_at()))
                : __('never fetched', 'otium-yachtfolio-sync'),
        ]);

        $this->card(__('Call budget', 'otium-yachtfolio-sync'), [
            __('Used', 'otium-yachtfolio-sync')    => sprintf('%d / %d', $budget->used(), $budget->limit()),
            __('Window resets', 'otium-yachtfolio-sync') => Menu::countdown($budget->window_resets_in()),
            __('Blocked (429)', 'otium-yachtfolio-sync') => $budget->blocked_for() > 0 ? Menu::countdown($budget->blocked_for()) : '—',
        ]);

        $this->card(__('Catalogue', 'otium-yachtfolio-sync'), [
            __('Synced', 'otium-yachtfolio-sync')    => (string) ($counts[YachtMapStore::STATUS_SYNCED] ?? 0),
            __('Errors', 'otium-yachtfolio-sync')    => (string) ($counts[YachtMapStore::STATUS_ERROR] ?? 0),
            __('Stale', 'otium-yachtfolio-sync')     => (string) ($counts[YachtMapStore::STATUS_STALE] ?? 0),
        ]);

        if ($run !== null) {
            $this->card(__('Last run', 'otium-yachtfolio-sync'), [
                __('Started', 'otium-yachtfolio-sync')  => Menu::stamp((string) $run['started_at']),
                __('Duration', 'otium-yachtfolio-sync') => Menu::duration((string) $run['started_at'], $run['finished_at'] !== null ? (string) $run['finished_at'] : null),
                __('Trigger', 'otium-yachtfolio-sync')  => (string) $run['trigger_source'] . ($run['dry_run'] ? ' (dry run)' : ''),
                __('Result', 'otium-yachtfolio-sync')   => sprintf(
                    __('created %d, updated %d, skipped %d, errors %d', 'otium-yachtfolio-sync'),
                    (int) $run['created'],
                    (int) $run['updated'],
                    (int) $run['skipped'],
                    (int) $run['errors']
                ),
                __('API calls', 'otium-yachtfolio-sync') => sprintf('%d + %d media', (int) $run['api_calls'], (int) $run['media_calls']),
            ]);
        }

        $this->card(__('Schedule', 'otium-yachtfolio-sync'), [
            __('Setting', 'otium-yachtfolio-sync') => (string) $settings->get('schedule', 'off'),
            __('Next pass', 'otium-yachtfolio-sync') => $next !== null
                ? sprintf(__('in %s', 'otium-yachtfolio-sync'), human_time_diff(time(), $next))
                : __('not scheduled', 'otium-yachtfolio-sync'),
            __('Detail scope', 'otium-yachtfolio-sync') => (string) $settings->get('detail_scope', 'selected'),
        ]);

        echo '</div></section>';

        Menu::close_page();
    }

    /** POST-only side effects, nonce checked, then redirect back. */
    public function handle_action(): void
    {
        Menu::require_cap();
        check_admin_referer('oy_yf_dashboard');

        $action = isset($_POST['oy_yf_do']) ? sanitize_key((string) wp_unslash($_POST['oy_yf_do'])) : '';

        switch ($action) {
            case 'sync':
            case 'dry_run':
                $summary = $this->plugin->orchestrator()->run_index([
                    'trigger' => 'admin',
                    'dry_run' => $action === 'dry_run',
                ]);
                if (empty($summary['ok'])) {
                    Menu::flash((string) ($summary['error'] ?? __('run failed', 'otium-yachtfolio-sync')), 'error');
                } else {
                    Menu::flash(sprintf(
                        __('Run %1$s started: %2$d yacht(s) queued.', 'otium-yachtfolio-sync'),
                        (string) $summary['run_id'],
                        (int) ($summary['queued'] ?? 0)
                    ));
                }
                break;

            case 'reference':
                $counts = $this->plugin->reference()->refresh(true);
                unset($counts['errors']);
                Menu::flash(sprintf(
                    __('Reference cache refreshed: %s', 'otium-yachtfolio-sync'),
                    implode(', ', array_map(
                        static fn(string $k, $v): string => $k . '=' . (is_array($v) ? count($v) : (int) $v),
                        array_keys($counts),
                        array_values($counts)
                    ))
                ));
                break;

            case 'check':
                $failed = [];
                foreach ($this->plugin->client()->ping() as $probe) {
                    if (!$probe['ok']) {
                        $failed[] = $probe['probe'] . ' (' . $probe['detail'] . ')';
                    }
                }
                $failed === []
                    ? Menu::flash(__('Every endpoint answered.', 'otium-yachtfolio-sync'))
                    : Menu::flash(__('Failed probes: ', 'otium-yachtfolio-sync') . implode('; ', $failed), 'error');
                break;

            default:
                Menu::flash(__('Unknown action.', 'otium-yachtfolio-sync'), 'error');
        }

        Menu::go(Menu::SLUG_DASHBOARD);
    }

    /** A single headline number. $tone is '', 'accent', 'warn' or 'bad'. */
    private function stat(string $label, string $value, string $hint = '', string $tone = ''): void
    {
        printf(
            '<div class="oy-card"><div class="oy-card__body oy-stat%s">'
            . '<span class="oy-stat__value">%s</span>'
            . '<span class="oy-stat__label">%s</span>%s</div></div>',
            $tone !== '' ? ' oy-stat--' . esc_attr($tone) : '',
            esc_html($value),
            esc_html($label),
            $hint !== '' ? '<span class="oy-stat__label oy-muted">' . esc_html($hint) . '</span>' : ''
        );
    }

    /** @param array<string,string> $rows */
    private function card(string $title, array $rows): void
    {
        echo '<div class="oy-card">';
        echo '<div class="oy-card__head"><h3 class="oy-card__title">' . esc_html($title) . '</h3></div>';
        echo '<div class="oy-card__body"><table class="oy-kv"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr><th>' . esc_html((string) $label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /** @param array<string,string> $buttons */
    private function action_form(array $buttons): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="oy-actions oy-actions--panel">';
        wp_nonce_field('oy_yf_dashboard');
        echo '<input type="hidden" name="action" value="oy_yf_action">';
        foreach ($buttons as $value => $label) {
            printf(
                '<button type="submit" class="oy-btn%s" name="oy_yf_do" value="%s">%s</button>',
                $value === 'sync' ? ' oy-btn--primary' : '',
                esc_attr($value),
                esc_html($label)
            );
        }
        echo '</form>';
    }
}
