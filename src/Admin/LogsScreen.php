<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Admin;

use Otium\Yachtfolio\Plugin;
use Otium\Yachtfolio\Support\Logger;

final class LogsScreen
{
    private const PER_PAGE = 50;

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_post_oy_yf_logs_export', [$this, 'export']);
        add_action('admin_post_oy_yf_logs_purge', [$this, 'purge']);
    }

    public function render(): void
    {
        Menu::require_cap();

        $filters = $this->filters();

        // 200 rows rendered a 13,600px page — unusable as a reading surface and
        // pointless as a scroll. Paged at 50, with the total shown so the
        // filters above can be judged against it.
        $total   = $this->plugin->logger()->count($filters);
        $pages   = max(1, (int) ceil($total / self::PER_PAGE));
        $paged   = min($pages, max(1, (int) ($_GET['paged'] ?? 1)));
        $rows    = $this->plugin->logger()->query($filters + [
            'limit'  => self::PER_PAGE,
            'offset' => ($paged - 1) * self::PER_PAGE,
        ]);

        Menu::open_page(
            Menu::SLUG_LOGS,
            __('Logs', 'otium-yachtfolio-sync'),
            __('Every sync decision is recorded here, including values kept instead of overwritten.', 'otium-yachtfolio-sync')
        );

        /* filters */
        echo '<form method="get" class="oy-filters">';
        printf('<input type="hidden" name="page" value="%s">', esc_attr(Menu::SLUG_LOGS));

        printf(
            '<input type="text" name="run" value="%s" placeholder="%s">',
            esc_attr((string) $filters['run_id']),
            esc_attr__('run id', 'otium-yachtfolio-sync')
        );

        echo '<select name="level"><option value="">' . esc_html__('any level', 'otium-yachtfolio-sync') . '</option>';
        foreach (Logger::LEVELS as $level) {
            printf('<option value="%s" %s>%s</option>', esc_attr($level), selected($filters['level'], $level, false), esc_html($level));
        }
        echo '</select>';

        echo '<select name="stage"><option value="">' . esc_html__('any stage', 'otium-yachtfolio-sync') . '</option>';
        foreach (Logger::STAGES as $stage) {
            printf('<option value="%s" %s>%s</option>', esc_attr($stage), selected($filters['stage'], $stage, false), esc_html($stage));
        }
        echo '</select>';

        printf(
            '<input type="number" name="yacht" value="%s" placeholder="%s" class="small-text">',
            $filters['yf_id'] > 0 ? (int) $filters['yf_id'] : '',
            esc_attr__('feed id', 'otium-yachtfolio-sync')
        );

        printf(
            '<input type="search" name="search" value="%s" placeholder="%s">',
            esc_attr((string) $filters['search']),
            esc_attr__('message contains', 'otium-yachtfolio-sync')
        );

        submit_button(__('Filter', 'otium-yachtfolio-sync'), 'secondary', '', false);
        echo '</form>';

        /* export + purge */
        echo '<p class="oy-actions">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field('oy_yf_logs');
        echo '<input type="hidden" name="action" value="oy_yf_logs_export">';
        foreach ($filters as $key => $value) {
            printf('<input type="hidden" name="%s" value="%s">', esc_attr((string) $key), esc_attr((string) $value));
        }
        submit_button(__('Export CSV', 'otium-yachtfolio-sync'), 'secondary', '', false);
        echo '</form> ';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field('oy_yf_logs');
        echo '<input type="hidden" name="action" value="oy_yf_logs_purge">';
        submit_button(
            sprintf(__('Purge older than %d days', 'otium-yachtfolio-sync'), $this->plugin->settings()->int('log_retention_days', 30)),
            'delete',
            '',
            false
        );
        echo '</form></p>';

        /* table */
        echo '<table class="oy-table oy-logs"><thead><tr>'
            . '<th>' . esc_html__('Time (UTC)', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Level', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Stage', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Feed ID', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Message', 'otium-yachtfolio-sync') . '</th>'
            . '</tr></thead><tbody>';

        if ($rows === []) {
            echo '<tr><td colspan="5">' . esc_html__('No log rows match.', 'otium-yachtfolio-sync') . '</td></tr>';
        }

        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . esc_html((string) $row['created_at']) . '</td>';
            echo '<td><span class="oy-pill oy-pill--level-' . esc_attr((string) $row['level']) . '">' . esc_html((string) $row['level']) . '</span></td>';
            echo '<td>' . esc_html((string) $row['stage']) . '</td>';
            echo '<td>' . esc_html((string) ($row['yf_id'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) $row['message']);
            if (!empty($row['context'])) {
                echo '<details><summary>' . esc_html__('context', 'otium-yachtfolio-sync') . '</summary><pre>'
                    . esc_html($this->pretty((string) $row['context'])) . '</pre></details>';
            }
            echo '</td></tr>';
        }

        echo '</tbody></table>';

        $this->pagination($paged, $pages, $total, $filters);

        Menu::close_page();
    }

    /**
     * @param array<string,mixed> $filters carried through so paging keeps the
     *                                     filter the admin is looking at
     */
    private function pagination(int $paged, int $pages, int $total, array $filters): void
    {
        $base = ['page' => Menu::SLUG_LOGS];
        foreach (['run_id' => 'run', 'level' => 'level', 'stage' => 'stage', 'search' => 'search'] as $key => $param) {
            if (!empty($filters[$key])) {
                $base[$param] = (string) $filters[$key];
            }
        }
        if (!empty($filters['yf_id'])) {
            $base['yacht'] = (int) $filters['yf_id'];
        }

        echo '<div class="tablenav bottom"><div class="tablenav-pages">';
        printf(
            '<span class="displaying-num">%s</span>',
            esc_html(sprintf(
                /* translators: %s: number of log rows */
                _n('%s entry', '%s entries', $total, 'otium-yachtfolio-sync'),
                number_format_i18n($total)
            ))
        );

        if ($pages > 1) {
            $link = static function (int $p, string $label, bool $disabled) use ($base): void {
                if ($disabled) {
                    printf('<span class="tablenav-pages-navspan" aria-hidden="true">%s</span>', esc_html($label));
                    return;
                }
                printf(
                    '<a class="page-numbers" href="%s">%s</a>',
                    esc_url(add_query_arg($base + ['paged' => $p], admin_url('admin.php'))),
                    esc_html($label)
                );
            };

            $link(1, '«', $paged <= 1);
            $link($paged - 1, '‹', $paged <= 1);
            printf(
                '<span class="paging-input">%s</span>',
                esc_html(sprintf(
                    /* translators: 1: current page, 2: total pages */
                    __('%1$d of %2$d', 'otium-yachtfolio-sync'),
                    $paged,
                    $pages
                ))
            );
            $link($paged + 1, '›', $paged >= $pages);
            $link($pages, '»', $paged >= $pages);
        }

        echo '</div></div>';
    }

    public function export(): void
    {
        Menu::require_cap();
        check_admin_referer('oy_yf_logs');

        $rows = $this->plugin->logger()->query($this->filters() + ['limit' => 1000]);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=yachtfolio-log-' . gmdate('Ymd-His') . '.csv');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }
        fputcsv($out, ['created_at', 'run_id', 'level', 'stage', 'yf_id', 'message', 'context']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['created_at'],
                $row['run_id'],
                $row['level'],
                $row['stage'],
                $row['yf_id'],
                $row['message'],
                $row['context'],
            ]);
        }
        fclose($out);
        exit;
    }

    public function purge(): void
    {
        Menu::require_cap();
        check_admin_referer('oy_yf_logs');

        $deleted = $this->plugin->logger()->purge();
        Menu::flash(sprintf(__('%d log row(s) deleted.', 'otium-yachtfolio-sync'), $deleted));
        Menu::go(Menu::SLUG_LOGS);
    }

    /** @return array{run_id:string,level:string,stage:string,yf_id:int,search:string} */
    private function filters(): array
    {
        return [
            'run_id' => isset($_REQUEST['run']) ? sanitize_text_field((string) wp_unslash($_REQUEST['run'])) : '',
            'level'  => isset($_REQUEST['level']) ? sanitize_key((string) $_REQUEST['level']) : '',
            'stage'  => isset($_REQUEST['stage']) ? sanitize_key((string) $_REQUEST['stage']) : '',
            'yf_id'  => isset($_REQUEST['yacht']) ? (int) $_REQUEST['yacht'] : 0,
            'search' => isset($_REQUEST['search']) ? sanitize_text_field((string) wp_unslash($_REQUEST['search'])) : '',
        ];
    }

    private function pretty(string $json): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json;
        }
        return (string) wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
