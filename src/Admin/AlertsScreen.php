<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Admin;

use Otium\Yachtfolio\Plugin;
use Otium\Yachtfolio\Sync\YachtMapStore;

/**
 * Everything that needs a human decision, in one place.
 *
 * These conditions used to be pushed into a `notice notice-warning` at the top
 * of every admin page. That competes with third-party plugin advertising for
 * the same strip of screen, so it was both easy to ignore and impossible to
 * act on — the notice listed yacht names but offered no way to do anything
 * about them.
 *
 * Here each condition is a row with the action that resolves it, and the tab
 * carries a live count so the information is still unmissable.
 */
final class AlertsScreen
{
    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
    }

    public function render(): void
    {
        Menu::require_cap();

        $map = $this->plugin->map();

        $detailLost = $map->count_detail_unavailable();
        $authLost   = $map->count_authorisation_lost();
        $errors     = $map->count(['status' => YachtMapStore::STATUS_ERROR]);
        $stale      = $map->count(['status' => YachtMapStore::STATUS_STALE]);
        $ungated    = $map->count(['published_not_visible' => true]);

        Menu::open_page(
            Menu::SLUG_ALERTS,
            __('Alerts', 'otium-yachtfolio-sync'),
            __('Conditions that need a decision. Nothing here is ever resolved automatically, and nothing here has unpublished or deleted anything.', 'otium-yachtfolio-sync')
        );

        echo '<div class="oy-grid">';
        $this->stat(
            __('Detail unavailable', 'otium-yachtfolio-sync'),
            $detailLost,
            __('rates and amenities cannot refresh', 'otium-yachtfolio-sync'),
            $detailLost > 0 ? 'warn' : ''
        );
        $this->stat(
            __('Authorisation lost', 'otium-yachtfolio-sync'),
            $authLost,
            __('listed but returning no data', 'otium-yachtfolio-sync'),
            $authLost > 0 ? 'bad' : ''
        );
        $this->stat(
            __('Sync errors', 'otium-yachtfolio-sync'),
            $errors,
            __('last pass failed for these', 'otium-yachtfolio-sync'),
            $errors > 0 ? 'bad' : ''
        );
        $this->stat(
            __('Live but not visible', 'otium-yachtfolio-sync'),
            $ungated,
            __('public with no media import', 'otium-yachtfolio-sync'),
            $ungated > 0 ? 'warn' : ''
        );
        $this->stat(
            __('Stale', 'otium-yachtfolio-sync'),
            $stale,
            __('gone from the feed', 'otium-yachtfolio-sync'),
            $stale > 0 ? 'warn' : ''
        );
        echo '</div>';

        $this->ungated_section($ungated);
        $this->attention_section();
        $this->error_log_section();

        Menu::close_page();
    }


    /**
     * Published yachts with the Visible gate closed.
     *
     * Not an error — it is what happens when a yacht was published by hand, or
     * before the gate existed. It matters because Visible is also what allows
     * media to be imported, so these pages are live with galleries that will
     * never be fetched. The fix is a bulk action, so this links straight at it
     * rather than listing rows that would only be read and not acted on.
     */
    private function ungated_section(int $count): void
    {
        if ($count === 0) {
            return;
        }

        echo '<section class="oy-section">';
        echo '<div class="oy-section__head"><h2 class="oy-section__title">'
            . esc_html__('Live but not visible', 'otium-yachtfolio-sync') . '</h2></div>';

        echo '<div class="oy-card oy-card--flag"><div class="oy-card__body">';
        printf(
            '<p>%s</p>',
            esc_html(sprintf(
                /* translators: %d: number of yachts */
                _n(
                    '%d published yacht has the Visible gate switched off.',
                    '%d published yachts have the Visible gate switched off.',
                    $count,
                    'otium-yachtfolio-sync'
                ),
                $count
            ))
        );
        printf(
            '<p class="description">%s</p>',
            esc_html__('Their pages are public, but because Visible also gates media import, no gallery is ever fetched for them. Nothing is wrong with the content already on the page — it simply will not gain images.', 'otium-yachtfolio-sync')
        );
        printf(
            '<p class="description">%s</p>',
            esc_html__('To fix: open the filtered list, tick the yachts you want public with media, and apply "Set visible and sync now". Leave any yacht you would rather keep without a gallery.', 'otium-yachtfolio-sync')
        );
        printf(
            '<p><a class="oy-btn oy-btn--primary" href="%s">%s</a></p>',
            esc_url(Menu::url(Menu::SLUG_YACHTS, ['view' => 'ungated'])),
            esc_html__('Review these yachts', 'otium-yachtfolio-sync')
        );
        echo '</div></div></section>';
    }
    /* ------------------------------------------------------------------ */

    private const SHOWN = 25;

    private function attention_section(): void
    {
        // 114 yachts carry a content flag, which rendered an 8,500px page.
        // This is a summary screen: show the head of the list and hand the rest
        // to the Yachts table, which is paginated and filterable.
        $total = $this->plugin->map()->count(['attention' => true]);
        $rows  = $this->plugin->map()->all(['attention' => true, 'limit' => self::SHOWN]);

        echo '<section class="oy-section">';
        echo '<div class="oy-section__head"><h2 class="oy-section__title">'
            . esc_html__('Yachts needing attention', 'otium-yachtfolio-sync') . '</h2>';
        if ($total > self::SHOWN) {
            printf(
                '<a class="oy-btn oy-btn--sm" href="%s">%s</a>',
                esc_url(Menu::url(Menu::SLUG_YACHTS, ['view' => 'attention'])),
                esc_html(sprintf(
                    /* translators: %d: total number of yachts */
                    __('See all %d', 'otium-yachtfolio-sync'),
                    $total
                ))
            );
        }
        echo '</div>';

        if ($rows === []) {
            echo '<div class="oy-empty"><p><strong>' . esc_html__('Nothing needs attention.', 'otium-yachtfolio-sync')
                . '</strong></p><p>' . esc_html__('Every yacht in the feed is answering normally.', 'otium-yachtfolio-sync')
                . '</p></div></section>';
            return;
        }

        echo '<p class="description" style="margin-bottom:var(--oy-3)">'
            . esc_html__('Content already written to these yachts is kept. They have not been unpublished; only refreshing is affected.', 'otium-yachtfolio-sync')
            . '</p>';

        echo '<table class="oy-table"><thead><tr>'
            . '<th>' . esc_html__('Yacht', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Condition', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Last good', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Do', 'otium-yachtfolio-sync') . '</th>'
            . '</tr></thead><tbody>';

        // The meaning of a condition is a property of the condition, not of the
        // yacht. Printed per row it was the same sentence five times over; it is
        // collected here and explained once below the table.
        $present = [];

        foreach ($rows as $row) {
            $yfId  = (int) $row['yf_id'];
            foreach (YachtMapStore::split_flags((string) $row['attention']) as $flag) {
                $present[$flag] = true;
            }

            echo '<tr>';

            printf(
                '<td><span class="oy-cell__title oy-trunc" title="%s">%s</span><span class="oy-cell__meta">#%d</span></td>',
                esc_attr((string) $row['yacht_name']),
                esc_html((string) $row['yacht_name']),
                $yfId
            );

            echo '<td>' . Menu::flag_pills((string) $row['attention']) . '</td>';

            printf(
                '<td class="oy-nowrap">%s</td>',
                esc_html(Menu::stamp(
                    isset($row['authorisation_last_ok_at']) && $row['authorisation_last_ok_at'] !== null
                        ? (string) $row['authorisation_last_ok_at']
                        : null
                ))
            );

            printf(
                '<td><a href="%s" class="oy-btn oy-btn--sm">%s</a></td>',
                esc_url(Menu::url(Menu::SLUG_YACHTS, ['s' => (string) $row['yacht_name']])),
                esc_html__('Open', 'otium-yachtfolio-sync')
            );

            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<div class="oy-legend">';
        foreach (array_keys($present) as $flag) {
            printf(
                '<p>%s <strong>%s</strong> — %s</p>',
                Menu::flag_pills($flag),
                esc_html($flag),
                esc_html($this->explain([$flag]))
            );
        }
        printf(
            '<p>%s</p>',
            esc_html__('If a yacht should still be chartered by Otium, ask Yachtfolio to restore the detail authorisation for it. Until then the brochure remains the only source for that yacht.', 'otium-yachtfolio-sync')
        );
        echo '</div>';

        echo '</section>';
    }

    private function error_log_section(): void
    {
        $rows = $this->plugin->logger()->query(['level' => 'error', 'limit' => 15]);

        echo '<section class="oy-section">';
        echo '<div class="oy-section__head"><h2 class="oy-section__title">'
            . esc_html__('Recent errors', 'otium-yachtfolio-sync') . '</h2>';
        printf(
            '<a class="oy-btn oy-btn--sm" href="%s">%s</a>',
            esc_url(Menu::url(Menu::SLUG_LOGS, ['level' => 'error'])),
            esc_html__('Open the full log', 'otium-yachtfolio-sync')
        );
        echo '</div>';

        if ($rows === []) {
            echo '<div class="oy-empty"><p>' . esc_html__('No errors have been logged.', 'otium-yachtfolio-sync') . '</p></div></section>';
            return;
        }

        echo '<table class="oy-table"><thead><tr>'
            . '<th>' . esc_html__('When (UTC)', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Stage', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Yacht', 'otium-yachtfolio-sync') . '</th>'
            . '<th>' . esc_html__('Message', 'otium-yachtfolio-sync') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $message = (string) $row['message'];
            printf(
                '<tr><td class="oy-nowrap">%s</td><td><span class="oy-pill oy-pill--level-error">%s</span></td>'
                . '<td class="oy-nowrap">%s</td><td><span class="oy-clamp" title="%s">%s</span></td></tr>',
                esc_html((string) $row['created_at']),
                esc_html((string) $row['stage']),
                $row['yf_id'] !== null && (int) $row['yf_id'] > 0 ? '#' . (int) $row['yf_id'] : '—',
                esc_attr($message),
                esc_html($message)
            );
        }

        echo '</tbody></table></section>';
    }

    /** @param array<int,string> $flags */
    private function explain(array $flags): string
    {
        if (in_array(YachtMapStore::ATTENTION_AUTH_LOST, $flags, true)) {
            return __('Yachtfolio still lists this yacht but every detail call comes back empty with no error. Its stored content is untouched.', 'otium-yachtfolio-sync');
        }
        if (in_array(YachtMapStore::ATTENTION_DETAIL_LOST, $flags, true)) {
            return __('No structured detail record, so rates, equipment and cruising areas cannot be refreshed. The brochure still works.', 'otium-yachtfolio-sync');
        }
        return __('Flagged during the last pass; open the yacht to see what the log recorded.', 'otium-yachtfolio-sync');
    }

    private function stat(string $label, int $value, string $hint, string $tone): void
    {
        printf(
            '<div class="oy-card"><div class="oy-card__body oy-stat%s">'
            . '<span class="oy-stat__value">%s</span>'
            . '<span class="oy-stat__label">%s</span>'
            . '<span class="oy-stat__label oy-muted">%s</span></div></div>',
            $tone !== '' ? ' oy-stat--' . esc_attr($tone) : '',
            esc_html(number_format_i18n($value)),
            esc_html($label),
            esc_html($hint)
        );
    }
}
