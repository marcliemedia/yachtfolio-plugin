<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Admin;

use Otium\Yachtfolio\Frontend\TemplateRouter;
use Otium\Yachtfolio\Plugin;

final class SettingsScreen
{
    private const GALLERIES = ['FULL', 'EXTERIOR', 'INTERIOR', 'LIFESTYLE', 'LAYOUT', 'PDF'];

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_post_oy_yf_save_settings', [$this, 'save']);
        add_action('admin_post_oy_yf_check_update', [$this, 'check_update']);
    }

    /**
     * Drops both the plugin's own release cache and WordPress's update
     * transient, so the next screen really re-asks GitHub instead of replaying
     * a cached answer.
     */
    public function check_update(): void
    {
        Menu::require_cap();
        check_admin_referer('oy_yf_check_update');

        $updater = $this->plugin->updater();
        $updater->flush();
        delete_site_transient('update_plugins');

        $release = $updater->release(true);
        if ($release === null) {
            $error = $updater->last_error();
            Menu::flash(
                $error !== '' ? $error : __('No release is published on GitHub yet.', 'otium-yachtfolio-sync'),
                $error !== '' ? 'error' : 'info'
            );
            Menu::go(Menu::SLUG_SETTINGS);
        }

        wp_update_plugins();

        Menu::flash(
            version_compare($release['version'], OY_YF_VERSION, '>')
                ? sprintf(
                    /* translators: 1: latest version, 2: installed version */
                    __('Version %1$s is available (installed: %2$s). Open Plugins → Installed Plugins to apply it.', 'otium-yachtfolio-sync'),
                    $release['version'],
                    OY_YF_VERSION
                )
                : sprintf(
                    /* translators: %s: version */
                    __('Up to date — the latest GitHub release is %s.', 'otium-yachtfolio-sync'),
                    $release['version']
                )
        );
        Menu::go(Menu::SLUG_SETTINGS);
    }

    public function render(): void
    {
        Menu::require_cap();
        $s = $this->plugin->settings();

        Menu::open_page(
            Menu::SLUG_SETTINGS,
            __('Settings', 'otium-yachtfolio-sync'),
            __('Connection, sync scope, media budget and the rules that protect hand-entered content.', 'otium-yachtfolio-sync')
        );

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('oy_yf_settings');
        echo '<input type="hidden" name="action" value="oy_yf_save_settings">';

        /* ---- connection ---- */
        $this->open_card(
            __('Connection', 'otium-yachtfolio-sync'),
            __('Credentials and endpoints for the Yachtfolio Public API.', 'otium-yachtfolio-sync')
        );

        $this->row(__('Mode', 'otium-yachtfolio-sync'), function () use ($s): void {
            echo '<div class="oy-choices">';
            foreach (['live' => __('LIVE', 'otium-yachtfolio-sync'), 'test' => __('TEST', 'otium-yachtfolio-sync')] as $value => $label) {
                printf(
                    '<label class="oy-choice"><input type="radio" name="mode" value="%s" %s> %s</label>',
                    esc_attr($value),
                    checked($s->mode(), $value, false),
                    esc_html($label)
                );
            }
            echo '</div>';
        });

        foreach (['live' => 'OY_YF_PASSKEY_LIVE', 'test' => 'OY_YF_PASSKEY_TEST'] as $mode => $constant) {
            $this->row(sprintf(__('%s passkey', 'otium-yachtfolio-sync'), strtoupper($mode)), function () use ($s, $mode, $constant): void {
                $fromConstant = defined($constant) && (string) constant($constant) !== '';
                printf(
                    '<input type="password" class="regular-text" name="passkey_%s" value="" placeholder="%s" autocomplete="off" %s>',
                    esc_attr($mode),
                    esc_attr($s->masked_passkey($mode) ?: __('not set', 'otium-yachtfolio-sync')),
                    $fromConstant ? 'readonly' : ''
                );
                echo '<p class="description">';
                echo $fromConstant
                    ? sprintf(esc_html__('Defined by the %s constant in wp-config.php; the database value is ignored.', 'otium-yachtfolio-sync'), esc_html($constant))
                    : esc_html__('Leave empty to keep the stored key.', 'otium-yachtfolio-sync');
                echo '</p>';
            });
        }

        $this->text_row($s, 'endpoint_basic', __('Basic endpoint', 'otium-yachtfolio-sync'), 'large-text');
        $this->text_row($s, 'endpoint_brochure', __('Brochure endpoint', 'otium-yachtfolio-sync'), 'large-text');
        $this->text_row($s, 'endpoint_media', __('Media endpoint', 'otium-yachtfolio-sync'), 'large-text');
        $this->number_row($s, 'request_timeout', __('Request timeout (s)', 'otium-yachtfolio-sync'));
        $this->number_row($s, 'budget_limit', __('Call budget per window', 'otium-yachtfolio-sync'), __('Yachtfolio allows 800 calls per 5 minutes across the API and media; keep a margin.', 'otium-yachtfolio-sync'));
        $this->number_row($s, 'budget_window', __('Budget window (s)', 'otium-yachtfolio-sync'));
        $this->close_card();

        /* ---- sync ---- */
        $this->open_card(
            __('Sync', 'otium-yachtfolio-sync'),
            __('How often the feed is read and how much detail is pulled per pass.', 'otium-yachtfolio-sync')
        );
        $this->select_row($s, 'schedule', __('Schedule', 'otium-yachtfolio-sync'), [
            'off'       => __('off', 'otium-yachtfolio-sync'),
            'hourly'    => __('hourly', 'otium-yachtfolio-sync'),
            'six_hours' => __('every 6 hours', 'otium-yachtfolio-sync'),
            'daily'     => __('daily', 'otium-yachtfolio-sync'),
        ]);
        $this->select_row($s, 'detail_scope', __('Detail scope', 'otium-yachtfolio-sync'), [
            'selected' => __('selected and linked yachts only (recommended)', 'otium-yachtfolio-sync'),
            'all'      => __('every yacht in the feed', 'otium-yachtfolio-sync'),
        ], __('Fetching detail for every yacht in the feed costs one API call each.', 'otium-yachtfolio-sync'));
        $this->number_row($s, 'batch_size', __('Yachts per queue wave', 'otium-yachtfolio-sync'));
        $this->checkbox_row($s, 'import_brochure', __('Fetch brochure detail', 'otium-yachtfolio-sync'), __('The brochure is the only detail source for yachts the key does not own.', 'otium-yachtfolio-sync'));
        $this->checkbox_row($s, 'create_terms', __('Create missing taxonomy terms', 'otium-yachtfolio-sync'));
        $this->close_card();

        /* ---- media ---- */
        $this->open_card(
            __('Media', 'otium-yachtfolio-sync'),
            __('Gallery import is the most expensive part of a pass — cap it deliberately.', 'otium-yachtfolio-sync')
        );
        $this->checkbox_row($s, 'import_media', __('Import gallery files', 'otium-yachtfolio-sync'), __('Only for yachts marked visible.', 'otium-yachtfolio-sync'));
        $this->row(__('Galleries', 'otium-yachtfolio-sync'), function () use ($s): void {
            $selected = (array) $s->get('media_galleries', ['FULL', 'LAYOUT']);
            echo '<div class="oy-choices">';
            foreach (self::GALLERIES as $bucket) {
                printf(
                    '<label class="oy-choice"><input type="checkbox" name="media_galleries[]" value="%s" %s> %s</label>',
                    esc_attr($bucket),
                    checked(in_array($bucket, $selected, true), true, false),
                    esc_html($bucket)
                );
            }
            echo '</div>';
            echo '<p class="description">' . esc_html__('EXTERIOR, INTERIOR, LIFESTYLE and PDF are subsets of FULL (verified on the live feed) — selecting them only creates duplicates. LAYOUT is not always inside FULL, so keep it.', 'otium-yachtfolio-sync') . '</p>';
        });
        $this->number_row($s, 'media_cap_per_yacht', __('Max files per yacht', 'otium-yachtfolio-sync'));
        $this->number_row($s, 'media_batch_size', __('Files per media job', 'otium-yachtfolio-sync'));
        $this->checkbox_row($s, 'import_sample_menu', __('Import the sample menu PDF', 'otium-yachtfolio-sync'));
        $this->checkbox_row($s, 'import_crew_photos', __('Import crew photos', 'otium-yachtfolio-sync'));
        $this->checkbox_row($s, 'set_featured_image', __('Set the featured image when the post has none', 'otium-yachtfolio-sync'));
        $this->close_card();

        /* ---- content protection ---- */
        $this->open_card(
            __('Content protection', 'otium-yachtfolio-sync'),
            __('What happens when the feed disagrees with a yacht typed in by hand.', 'otium-yachtfolio-sync')
        );
        $this->select_row($s, 'write_mode', __('When the feed disagrees with a hand-entered yacht', 'otium-yachtfolio-sync'), [
            'fill_empty_only'    => __('Keep what is on the site, only fill empty fields (recommended)', 'otium-yachtfolio-sync'),
            'feed_authoritative' => __('Let the feed overwrite everything', 'otium-yachtfolio-sync'),
        ]);
        echo '<tr><th>' . esc_html__('How it works', 'otium-yachtfolio-sync') . '</th><td class="description">'
            . esc_html__('Every yacht carries a provenance marker. Yachts typed in by hand are protected: an existing value is never replaced and taxonomy terms are only added, never removed. Yachts created by the importer are owned by the feed and are refreshed normally. Whenever a value is kept instead of overwritten it is recorded on the yacht and in the log, so nothing is hidden.', 'otium-yachtfolio-sync')
            . '</td></tr>';
        $this->close_card();

        /* ---- feed yacht presentation ---- */
        $this->open_card(
            __('Feed yacht presentation', 'otium-yachtfolio-sync'),
            __('Which template renders yachts created by the importer.', 'otium-yachtfolio-sync')
        );
        $templates = [0 => __('— off: every yacht uses the normal template —', 'otium-yachtfolio-sync')] + TemplateRouter::content_templates();
        $this->select_row($s, TemplateRouter::OPTION_KEY, __('Single template for yachts created from the feed', 'otium-yachtfolio-sync'), $templates);
        echo '<tr><th>' . esc_html__('How it works', 'otium-yachtfolio-sync') . '</th><td class="description">'
            . esc_html__('Yachts created by the importer carry yf_origin = feed and will be rendered with the template selected here. Hand-entered yachts always keep the original "Yacht - Single" template. Both groups stay in the same post type, so every query loop and every filter on the site is unaffected.', 'otium-yachtfolio-sync')
            . '<br><strong>' . esc_html__('Note:', 'otium-yachtfolio-sync') . '</strong> '
            . esc_html__('this routing is done in PHP, so it does NOT appear in the template\'s own Conditions panel in Bricks. The Yachtfolio box on each yacht shows which template will render it.', 'otium-yachtfolio-sync')
            . '</td></tr>';
        $this->close_card();

        /* ---- prices ---- */
        $this->open_card(
            __('Prices', 'otium-yachtfolio-sync'),
            __('How seasonal rates from the feed are turned into the three displayed prices.', 'otium-yachtfolio-sync')
        );
        $this->select_row($s, 'shoulder_source', __('Shoulder season amount', 'otium-yachtfolio-sync'), [
            'next_season_min' => __('next season minimum', 'otium-yachtfolio-sync'),
            'next_season_max' => __('next season maximum', 'otium-yachtfolio-sync'),
        ]);
        $this->text_row($s, 'currency_locale', __('Currency locale', 'otium-yachtfolio-sync'), 'small-text');
        echo '<tr><th>' . esc_html__('Rule', 'otium-yachtfolio-sync') . '</th><td class="description">'
            . esc_html__('High = current season maximum, Low = current season minimum, Shoulder = next season. The currency always comes from the feed row; a missing amount renders empty, never 0.', 'otium-yachtfolio-sync')
            . '</td></tr>';
        $this->close_card();

        /* ---- housekeeping ---- */
        $this->open_card(
            __('Housekeeping', 'otium-yachtfolio-sync'),
            __('Retention and what happens if the plugin is removed.', 'otium-yachtfolio-sync')
        );
        $this->number_row($s, 'log_retention_days', __('Keep logs for (days)', 'otium-yachtfolio-sync'));
        $this->number_row($s, 'reference_ttl', __('Reference cache TTL (s)', 'otium-yachtfolio-sync'));
        $this->checkbox_row($s, 'remove_data_on_uninstall', __('Delete plugin tables on uninstall', 'otium-yachtfolio-sync'), __('Yacht posts, meta and media are never removed.', 'otium-yachtfolio-sync'));
        $this->close_card();

        /* ---- updates ---- */
        $updater = $this->plugin->updater();
        $this->open_card(
            __('Updates', 'otium-yachtfolio-sync'),
            sprintf(
                /* translators: %s: repository URL */
                __('New versions are published as GitHub releases on %s and offered in Plugins → Updates.', 'otium-yachtfolio-sync'),
                \Otium\Yachtfolio\Update\GitHubUpdater::repository_url()
            )
        );

        $this->row(__('GitHub token', 'otium-yachtfolio-sync'), static function () use ($updater): void {
            $fromConstant = $updater->token_source() === 'wp-config.php';
            printf(
                '<input type="password" class="regular-text" name="github_token" value="" placeholder="%s" autocomplete="off" %s>',
                esc_attr($updater->masked_token() ?: __('not set', 'otium-yachtfolio-sync')),
                $fromConstant ? 'readonly' : ''
            );
            echo '<p class="description">';
            if ($fromConstant) {
                echo esc_html__('Defined by the OY_YF_GITHUB_TOKEN constant in wp-config.php; the database value is ignored.', 'otium-yachtfolio-sync');
            } else {
                echo esc_html__('Only needed while the repository is private. A fine-grained token with read-only Contents access on this one repository is enough. Leave empty to keep the stored token.', 'otium-yachtfolio-sync');
                echo '</p><p class="description"><label class="oy-choice"><input type="checkbox" name="github_token_clear" value="1"> '
                    . esc_html__('Remove the stored token', 'otium-yachtfolio-sync') . '</label>';
            }
            echo '</p>';
        });

        $this->row(__('Token source', 'otium-yachtfolio-sync'), static function () use ($updater): void {
            printf('<span class="oy-pill oy-pill--info">%s</span>', esc_html($updater->token_source()));
        });

        $this->checkbox_row(
            $s,
            'update_prereleases',
            __('Offer pre-releases', 'otium-yachtfolio-sync'),
            __('Off means only the latest full release is offered. Turn on to test a release candidate before it is promoted.', 'otium-yachtfolio-sync')
        );

        $this->row(__('Status', 'otium-yachtfolio-sync'), static function () use ($updater): void {
            $release = $updater->release();
            $error   = $updater->last_error();

            printf(
                '<p><strong>%s</strong> %s</p>',
                esc_html__('Installed:', 'otium-yachtfolio-sync'),
                esc_html(OY_YF_VERSION)
            );

            if ($release !== null) {
                $newer = version_compare($release['version'], OY_YF_VERSION, '>');
                printf(
                    '<p><strong>%s</strong> %s <span class="oy-pill oy-pill--%s">%s</span></p>',
                    esc_html__('Latest release:', 'otium-yachtfolio-sync'),
                    esc_html($release['version']),
                    $newer ? 'warn' : 'ok',
                    $newer
                        ? esc_html__('update available', 'otium-yachtfolio-sync')
                        : esc_html__('up to date', 'otium-yachtfolio-sync')
                );
            } elseif ($error !== '') {
                printf('<p><span class="oy-pill oy-pill--danger">%s</span> %s</p>', esc_html__('error', 'otium-yachtfolio-sync'), esc_html($error));
            } else {
                printf('<p class="oy-muted">%s</p>', esc_html__('No release published yet.', 'otium-yachtfolio-sync'));
            }

            printf(
                '<p><a class="oy-btn oy-btn--sm" href="%s">%s</a></p>',
                esc_url(wp_nonce_url(admin_url('admin-post.php?action=oy_yf_check_update'), 'oy_yf_check_update')),
                esc_html__('Check for updates now', 'otium-yachtfolio-sync')
            );
        });
        $this->close_card();

        echo '<div class="oy-savebar">';
        echo '<span class="oy-muted">' . esc_html__('Changes apply to the next sync pass.', 'otium-yachtfolio-sync') . '</span>';
        echo '<button type="submit" class="oy-btn oy-btn--primary">' . esc_html__('Save settings', 'otium-yachtfolio-sync') . '</button>';
        echo '</div>';

        echo '</form>';
        Menu::close_page();
    }

    public function save(): void
    {
        Menu::require_cap();
        check_admin_referer('oy_yf_settings');

        $post = wp_unslash($_POST);
        $patch = [];

        $patch['mode'] = ($post['mode'] ?? 'live') === 'test' ? 'test' : 'live';

        foreach (['live', 'test'] as $mode) {
            $value = trim((string) ($post['passkey_' . $mode] ?? ''));
            if ($value !== '') {
                // Only overwrite when a real key was typed; the field shows a mask.
                // Validate, never silently strip: the old preg_replace() deleted
                // '-', '_' and '.' from a pasted key and stored a broken value with
                // no warning, producing auth failures that were near-impossible to trace.
                if (preg_match('/^[A-Za-z0-9._-]{16,128}$/', $value) !== 1) {
                    Menu::flash(sprintf(
                        /* translators: %s: test or live */
                        __('The %s passkey was rejected: expected 16-128 characters, letters, digits, dot, dash or underscore only. Nothing was saved for it.', 'otium-yachtfolio-sync'),
                        $mode
                    ), 'error');
                    continue;
                }
                $patch['passkey_' . $mode] = $value;
            }
        }

        foreach (['endpoint_basic', 'endpoint_brochure', 'endpoint_media'] as $key) {
            if (isset($post[$key])) {
                $patch[$key] = esc_url_raw(trim((string) $post[$key]));
            }
        }

        foreach (['request_timeout', 'budget_limit', 'budget_window', 'batch_size', 'media_batch_size', 'media_cap_per_yacht', 'log_retention_days', 'reference_ttl'] as $key) {
            if (isset($post[$key])) {
                $patch[$key] = max(0, (int) $post[$key]);
            }
        }

        foreach (['import_brochure', 'import_media', 'create_terms', 'import_sample_menu', 'import_crew_photos', 'set_featured_image', 'remove_data_on_uninstall', 'update_prereleases'] as $key) {
            $patch[$key] = !empty($post[$key]);
        }

        // Blank keeps the stored token, same contract as the passkey fields.
        // A pasted GitHub token is validated rather than sanitised, so a
        // mistyped value fails loudly instead of being stored broken.
        $token = trim((string) ($post['github_token'] ?? ''));
        if ($token !== '') {
            if (preg_match('/^[A-Za-z0-9_]{20,255}$/', $token) === 1) {
                $patch['github_token'] = $token;
            } else {
                Menu::flash(__('The GitHub token was rejected: expected 20-255 characters, letters, digits or underscore. Nothing was saved for it.', 'otium-yachtfolio-sync'), 'error');
            }
        } elseif (!empty($post['github_token_clear'])) {
            $patch['github_token'] = '';
        }

        $patch['schedule'] = in_array($post['schedule'] ?? 'off', ['off', 'hourly', 'six_hours', 'daily'], true)
            ? (string) $post['schedule']
            : 'off';

        $patch['detail_scope'] = ($post['detail_scope'] ?? 'selected') === 'all' ? 'all' : 'selected';
        $patch['write_mode'] = ($post['write_mode'] ?? '') === 'feed_authoritative'
            ? 'feed_authoritative'
            : 'fill_empty_only';

        // Only accept a published Bricks CONTENT template, or 0 for off. A bad
        // id here would silently blank the single yacht page.
        $tpl = (int) ($post[TemplateRouter::OPTION_KEY] ?? 0);
        if ($tpl > 0 && !array_key_exists($tpl, TemplateRouter::content_templates())) {
            Menu::flash(__('That template is not a published Bricks content template — the setting was left unchanged.', 'otium-yachtfolio-sync'), 'error');
        } else {
            $patch[TemplateRouter::OPTION_KEY] = $tpl;
        }
        $patch['shoulder_source'] = ($post['shoulder_source'] ?? '') === 'next_season_max' ? 'next_season_max' : 'next_season_min';
        $patch['currency_locale'] = sanitize_key((string) ($post['currency_locale'] ?? 'hr'));

        $galleries = [];
        foreach ((array) ($post['media_galleries'] ?? []) as $bucket) {
            $bucket = strtoupper(sanitize_text_field((string) $bucket));
            if (in_array($bucket, self::GALLERIES, true)) {
                $galleries[] = $bucket;
            }
        }
        $patch['media_galleries'] = $galleries !== [] ? $galleries : ['FULL', 'LAYOUT'];

        $this->plugin->settings()->set($patch);
        $this->plugin->jobs()->sync_schedule();

        // A changed token or channel invalidates whatever release was cached.
        if (array_key_exists('github_token', $patch) || array_key_exists('update_prereleases', $patch)) {
            $this->plugin->updater()->flush();
        }

        Menu::flash(__('Settings saved.', 'otium-yachtfolio-sync'));
        Menu::go(Menu::SLUG_SETTINGS);
    }

    /* ------------------------------------------------------------------ */

    /** Opens a titled settings card whose body is one `form-table`. */
    private function open_card(string $title, string $subtitle = ''): void
    {
        echo '<section class="oy-form-card">';
        echo '<header class="oy-form-card__head">';
        printf('<h2 class="oy-form-card__title">%s</h2>', esc_html($title));
        if ($subtitle !== '') {
            printf('<p class="oy-form-card__sub">%s</p>', esc_html($subtitle));
        }
        echo '</header>';
        echo '<table class="form-table" role="presentation"><tbody>';
    }

    private function close_card(): void
    {
        echo '</tbody></table></section>';
    }

    private function row(string $label, callable $field): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
        $field();
        echo '</td></tr>';
    }

    private function text_row(\Otium\Yachtfolio\Support\Settings $s, string $key, string $label, string $class = 'regular-text'): void
    {
        $this->row($label, static function () use ($s, $key, $class): void {
            printf(
                '<input type="text" class="%s" name="%s" value="%s">',
                esc_attr($class),
                esc_attr($key),
                esc_attr((string) $s->get($key, ''))
            );
        });
    }

    private function number_row(\Otium\Yachtfolio\Support\Settings $s, string $key, string $label, string $description = ''): void
    {
        $this->row($label, static function () use ($s, $key, $description): void {
            printf(
                '<input type="number" class="small-text" name="%s" value="%d" min="0">',
                esc_attr($key),
                (int) $s->get($key, 0)
            );
            if ($description !== '') {
                echo '<p class="description">' . esc_html($description) . '</p>';
            }
        });
    }

    private function checkbox_row(\Otium\Yachtfolio\Support\Settings $s, string $key, string $label, string $description = ''): void
    {
        $this->row($label, static function () use ($s, $key, $description): void {
            printf(
                '<label class="oy-choice"><input type="checkbox" name="%s" value="1" %s> %s</label>',
                esc_attr($key),
                checked($s->bool($key), true, false),
                esc_html__('Enabled', 'otium-yachtfolio-sync')
            );
            if ($description !== '') {
                echo '<p class="description">' . esc_html($description) . '</p>';
            }
        });
    }

    /** @param array<string,string> $choices */
    private function select_row(\Otium\Yachtfolio\Support\Settings $s, string $key, string $label, array $choices, string $description = ''): void
    {
        $this->row($label, static function () use ($s, $key, $choices, $description): void {
            printf('<select name="%s">', esc_attr($key));
            $current = (string) $s->get($key, '');
            foreach ($choices as $value => $text) {
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr((string) $value),
                    selected($current, (string) $value, false),
                    esc_html($text)
                );
            }
            echo '</select>';
            if ($description !== '') {
                echo '<p class="description">' . esc_html($description) . '</p>';
            }
        });
    }
}
