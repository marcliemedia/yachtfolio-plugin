<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Cli;

use Otium\Yachtfolio\Mapping\MappingReport;
use Otium\Yachtfolio\Plugin;
use Otium\Yachtfolio\Sync\YachtMapStore;
use WP_CLI;
use WP_CLI\Utils;

/**
 * `wp otium-yf <command>` — the primary control surface. The first full import
 * (433 yachts, thousands of files) belongs on the CLI, not in a browser tab.
 */
final class Commands
{
    private function plugin(): Plugin
    {
        return Plugin::instance();
    }

    /**
     * Probes every endpoint and prints the connection state.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json or csv. Default: table.
     */
    public function check(array $args, array $assoc): void
    {
        $plugin = $this->plugin();
        $settings = $plugin->settings();

        WP_CLI::log(sprintf(
            'mode=%s  passkey=%s (%s)  budget=%d/%d used, window resets in %ds',
            $settings->mode(),
            $settings->masked_passkey() ?: '(none)',
            $settings->passkey_source(),
            $plugin->budget()->used(),
            $plugin->budget()->limit(),
            $plugin->budget()->window_resets_in()
        ));

        if ($settings->passkey() === '') {
            WP_CLI::error('no passkey configured: set OY_YF_PASSKEY_LIVE in wp-config.php or save one in Settings');
        }

        $rows = [];
        foreach ($plugin->client()->ping() as $probe) {
            $rows[] = [
                'probe'  => $probe['probe'],
                'ok'     => $probe['ok'] ? 'yes' : 'NO',
                'detail' => $probe['detail'],
            ];
        }

        Utils\format_items((string) ($assoc['format'] ?? 'table'), $rows, ['probe', 'ok', 'detail']);

        $failed = count(array_filter($rows, static fn(array $r): bool => $r['ok'] === 'NO'));
        if ($failed > 0) {
            WP_CLI::warning("$failed probe(s) failed");
        } else {
            WP_CLI::success('all probes passed');
        }
    }

    /**
     * Runs the index pass: refresh reference tables, pull the yacht index, mark
     * stale rows and queue detail jobs.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * [--scope=<scope>]
     * : selected (default) or all.
     * [--limit=<n>]
     * [--force]
     * [--skip-enqueue]
     * : compute candidates without queueing anything.
     *
     * WP-CLI reads a leading `--no-` as the negation of a declared flag, so the
     * documented `--no-enqueue` was rejected with "unknown --enqueue parameter".
     */
    public function index(array $args, array $assoc): void
    {
        $summary = $this->plugin()->orchestrator()->run_index([
            'dry_run' => isset($assoc['dry-run']),
            'trigger' => 'cli',
            'scope'   => (string) ($assoc['scope'] ?? $this->plugin()->settings()->get('detail_scope', 'selected')),
            'limit'   => (int) ($assoc['limit'] ?? 0),
            'force'   => isset($assoc['force']),
            'enqueue' => !isset($assoc['skip-enqueue']),
        ]);

        if (empty($summary['ok'])) {
            WP_CLI::error((string) ($summary['error'] ?? 'index pass failed'));
        }

        $candidates = is_array($summary['candidates'] ?? null) ? count($summary['candidates']) : 0;
        WP_CLI::log(sprintf(
            'run %s: index created=%d updated=%d changed=%d, owned=%d, stale=%d, candidates=%d, api calls=%d',
            (string) $summary['run_id'],
            (int) ($summary['index']['created'] ?? 0),
            (int) ($summary['index']['updated'] ?? 0),
            (int) ($summary['index']['changed'] ?? 0),
            (int) ($summary['owned'] ?? 0),
            (int) ($summary['stale'] ?? 0),
            $candidates,
            (int) ($summary['api_calls'] ?? 0)
        ));
        WP_CLI::success('index pass finished');
    }

    /**
     * Marks yachts as selected so the detail pass fetches them.
     *
     * ## OPTIONS
     *
     * [--yacht=<yf_id>]
     * [--all-linked]
     * [--off]
     */
    public function select(array $args, array $assoc): void
    {
        $map = $this->plugin()->map();
        $on = !isset($assoc['off']);
        $count = 0;

        if (isset($assoc['yacht'])) {
            $map->set_selected((int) $assoc['yacht'], $on);
            $count = 1;
        } elseif (isset($assoc['all-linked'])) {
            foreach ($map->all(['linked' => true, 'limit' => 1000]) as $row) {
                $map->set_selected((int) $row['yf_id'], $on);
                $count++;
            }
        } else {
            WP_CLI::error('pass --yacht=<yf_id> or --all-linked');
        }

        WP_CLI::success(sprintf('%s %d yacht(s)', $on ? 'selected' : 'unselected', $count));
    }

    /**
     * Syncs one yacht or walks the candidate list synchronously.
     *
     * ## OPTIONS
     *
     * [--yacht=<yf_id>]
     * [--dry-run]
     * [--force]
     * [--no-media]
     * [--limit=<n>]
     */
    public function sync(array $args, array $assoc): void
    {
        $orchestrator = $this->plugin()->orchestrator();
        $opts = [
            'dry_run'    => isset($assoc['dry-run']),
            'force'      => isset($assoc['force']),
            'with_media' => !isset($assoc['no-media']),
            'trigger'    => 'cli',
        ];

        if (isset($assoc['yacht'])) {
            $result = $orchestrator->sync_yacht((int) $assoc['yacht'], $opts);
            $this->print_result((int) $assoc['yacht'], $result);
            if ($result['status'] === 'error') {
                WP_CLI::error('sync failed');
            }
            WP_CLI::success('done');
            return;
        }

        $summary = $orchestrator->run_index([
            'trigger' => 'cli',
            'limit'   => (int) ($assoc['limit'] ?? 0),
            'force'   => isset($assoc['force']),
            'enqueue' => false,
            'dry_run' => $opts['dry_run'],
        ]);

        if (empty($summary['ok'])) {
            WP_CLI::error((string) ($summary['error'] ?? 'index pass failed'));
        }

        $candidates = is_array($summary['candidates'] ?? null) ? $summary['candidates'] : [];
        if ($candidates === []) {
            WP_CLI::success('nothing to sync (everything is up to date)');
            return;
        }

        $opts['run_id'] = (string) $summary['run_id'];
        $progress = Utils\make_progress_bar('syncing ' . count($candidates) . ' yacht(s)', count($candidates));
        $counts = ['synced' => 0, 'skipped' => 0, 'dry_run' => 0, 'error' => 0];

        foreach ($candidates as $yfId) {
            try {
                $result = $orchestrator->sync_yacht((int) $yfId, $opts);
                $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
            } catch (\Throwable $e) {
                $counts['error']++;
                WP_CLI::warning(sprintf('yacht %d: %s', (int) $yfId, $e->getMessage()));
            }
            $progress->tick();
        }
        $progress->finish();

        $orchestrator->finalize((string) $summary['run_id']);

        WP_CLI::log(sprintf(
            'synced=%d skipped=%d dry_run=%d errors=%d',
            $counts['synced'],
            $counts['skipped'],
            $counts['dry_run'],
            $counts['error']
        ));
        $counts['error'] > 0 ? WP_CLI::warning('finished with errors') : WP_CLI::success('finished');
    }

    /**
     * Imports media for one yacht.
     *
     * ## OPTIONS
     *
     * --yacht=<yf_id>
     * [--limit=<n>]
     */
    public function media(array $args, array $assoc): void
    {
        if (!isset($assoc['yacht'])) {
            WP_CLI::error('--yacht=<yf_id> is required');
        }

        $result = $this->plugin()->orchestrator()->sync_media((int) $assoc['yacht']);
        if (empty($result['ok'])) {
            WP_CLI::error((string) ($result['message'] ?? 'media import failed'));
        }

        WP_CLI::success(sprintf(
            'imported=%d skipped=%d failed=%d',
            (int) $result['imported'],
            (int) $result['skipped'],
            (int) $result['failed']
        ));
    }

    /**
     * Links existing yacht posts to feed rows.
     *
     * ## OPTIONS
     *
     * [--suggest]
     * [--confirm=<yf_id:post_id>]
     * [--auto-exact]
     * [--format=<format>]
     */
    public function link(array $args, array $assoc): void
    {
        $linker = $this->plugin()->linker();

        if (isset($assoc['confirm'])) {
            $parts = explode(':', (string) $assoc['confirm']);
            if (count($parts) !== 2) {
                WP_CLI::error('use --confirm=<yf_id>:<post_id>');
            }
            try {
                $linker->confirm((int) $parts[0], (int) $parts[1]);
            } catch (\Throwable $e) {
                WP_CLI::error($e->getMessage());
            }
            WP_CLI::success(sprintf('linked yacht %d to post %d', (int) $parts[0], (int) $parts[1]));
            return;
        }

        $suggestions = $linker->suggest();
        if ($suggestions === []) {
            WP_CLI::success('no unlinked matches found');
            return;
        }

        Utils\format_items(
            (string) ($assoc['format'] ?? 'table'),
            $suggestions,
            ['yf_id', 'yf_name', 'post_id', 'post_title', 'score', 'kind']
        );

        if (!isset($assoc['auto-exact'])) {
            WP_CLI::log('re-run with --auto-exact to link the exact matches, or --confirm=<yf_id>:<post_id> one at a time');
            return;
        }

        $linked = 0;
        foreach ($suggestions as $suggestion) {
            if ($suggestion['kind'] !== 'exact') {
                continue;
            }
            try {
                $linker->confirm((int) $suggestion['yf_id'], (int) $suggestion['post_id']);
                $linked++;
            } catch (\Throwable $e) {
                WP_CLI::warning(sprintf('%s: %s', $suggestion['post_title'], $e->getMessage()));
            }
        }
        WP_CLI::success("linked $linked exact match(es)");
    }

    /**
     * Shows the yacht map.
     *
     * ## OPTIONS
     *
     * [--attention]
     * [--owned]
     * [--status=<status>]
     * [--limit=<n>]
     * [--format=<format>]
     */
    public function status(array $args, array $assoc): void
    {
        $map = $this->plugin()->map();

        $query = ['limit' => (int) ($assoc['limit'] ?? 50)];
        if (isset($assoc['attention'])) {
            $query['attention'] = true;
        }
        if (isset($assoc['owned'])) {
            $query['owned'] = true;
        }
        if (isset($assoc['status'])) {
            $query['status'] = (string) $assoc['status'];
        }

        $rows = [];
        foreach ($map->all($query) as $row) {
            $rows[] = [
                'yf_id'     => (int) $row['yf_id'],
                'name'      => (string) $row['yacht_name'],
                'post_id'   => (int) ($row['post_id'] ?? 0),
                'status'    => (string) $row['status'],
                'owned'     => $row['owned'] ? 'yes' : '',
                'selected'  => $row['selected'] ? 'yes' : '',
                'images'    => sprintf('%d/%d', (int) $row['image_count'], (int) $row['media_total']),
                'modified'  => (string) $row['last_modified_remote'],
                'synced'    => (string) ($row['last_synced_at'] ?? ''),
                'attention' => (string) $row['attention'],
            ];
        }

        Utils\format_items(
            (string) ($assoc['format'] ?? 'table'),
            $rows,
            ['yf_id', 'name', 'post_id', 'status', 'owned', 'selected', 'images', 'modified', 'synced', 'attention']
        );

        $counts = $map->status_counts();
        WP_CLI::log('totals: ' . implode('  ', array_map(
            static fn(string $k, int $v): string => "$k=$v",
            array_keys($counts),
            array_values($counts)
        )));

        $detail = $map->count_detail_unavailable();
        $lost   = $map->count_authorisation_lost();
        $protected = $this->count_protected_yachts();

        WP_CLI::log(sprintf(
            'attention: detail_record_unavailable=%d  authorisation_lost=%d  yachts_with_protected_fields=%d',
            $detail,
            $lost,
            $protected
        ));

        if ($detail > 0 || $lost > 0) {
            // Non-zero exit so a cron wrapper can page a human instead of
            // logging "Success" while yachts quietly rot.
            WP_CLI::halt(2);
        }
    }

    /** Yachts where the feed disagreed with hand-entered content. */
    private function count_protected_yachts(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'yf_protected_count' AND meta_value > 0"
        );
    }

    /**
     * Reads the log table.
     *
     * ## OPTIONS
     *
     * [--run=<run_id>]
     * [--level=<level>]
     * [--stage=<stage>]
     * [--yacht=<yf_id>]
     * [--limit=<n>]
     * [--format=<format>]
     */
    public function log(array $args, array $assoc): void
    {
        $rows = $this->plugin()->logger()->query([
            'run_id' => (string) ($assoc['run'] ?? ''),
            'level'  => (string) ($assoc['level'] ?? ''),
            'stage'  => (string) ($assoc['stage'] ?? ''),
            'yf_id'  => (int) ($assoc['yacht'] ?? 0),
            'limit'  => (int) ($assoc['limit'] ?? 50),
        ]);

        $items = array_map(static fn(array $row): array => [
            'time'    => (string) $row['created_at'],
            'level'   => (string) $row['level'],
            'stage'   => (string) $row['stage'],
            'yf_id'   => (string) ($row['yf_id'] ?? ''),
            'message' => (string) $row['message'],
        ], $rows);

        Utils\format_items((string) ($assoc['format'] ?? 'table'), $items, ['time', 'level', 'stage', 'yf_id', 'message']);
    }

    /**
     * Field coverage across the 73 JetEngine fields.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     */
    public function coverage(array $args, array $assoc): void
    {
        global $wpdb;

        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_fields FROM {$wpdb->prefix}jet_post_types WHERE slug = %s",
            'yacht'
        ));

        $fields = maybe_unserialize((string) $raw);
        if (!is_array($fields)) {
            WP_CLI::error('could not read the JetEngine field definition for the yacht post type');
        }

        $postIds = [];
        foreach ($this->plugin()->map()->all(['status' => YachtMapStore::STATUS_SYNCED, 'limit' => 1000]) as $row) {
            if (!empty($row['post_id'])) {
                $postIds[] = (int) $row['post_id'];
            }
        }

        if ($postIds === []) {
            WP_CLI::warning('no synced yachts yet; coverage needs at least one');
            return;
        }

        $rows = [];
        $filled = 0;
        foreach ($fields as $field) {
            $key = (string) ($field['name'] ?? '');
            if ($key === '') {
                continue;
            }
            $count = 0;
            foreach ($postIds as $postId) {
                $value = get_post_meta($postId, $key, true);
                if (is_array($value) ? $value !== [] : trim((string) $value) !== '') {
                    $count++;
                }
            }
            if ($count > 0) {
                $filled++;
            }
            $rows[] = [
                'field'  => $key,
                'type'   => (string) ($field['type'] ?? ''),
                'filled' => sprintf('%d/%d', $count, count($postIds)),
            ];
        }

        Utils\format_items((string) ($assoc['format'] ?? 'table'), $rows, ['field', 'type', 'filled']);
        WP_CLI::log(sprintf(
            'coverage: %d of %d fields carry a value on at least one synced yacht (%.1f%%)',
            $filled,
            count($rows),
            count($rows) > 0 ? ($filled / count($rows)) * 100 : 0.0
        ));
    }

    /**
     * Taxonomy migration report.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     */
    public function taxonomy_report(array $args, array $assoc): void
    {
        $rows = [];
        foreach (['yacht-cabins', 'yacht-guests', 'yacht-destination', 'yacht-type'] as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            if (is_wp_error($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                $rows[] = [
                    'taxonomy' => $taxonomy,
                    'term'     => $term->name,
                    'posts'    => (int) $term->count,
                    'shape'    => $this->term_shape($taxonomy, $term->name),
                ];
            }
        }

        Utils\format_items((string) ($assoc['format'] ?? 'table'), $rows, ['taxonomy', 'term', 'posts', 'shape']);

        $report = MappingReport::load();
        if (!$report->is_empty()) {
            WP_CLI::log('unmapped values from the last run:');
            foreach ($report->all() as $kind => $values) {
                foreach ($values as $value => $count) {
                    WP_CLI::log(sprintf('  %-10s %-40s x%d', $kind, $value, $count));
                }
            }
        }
    }

    /**
     * Reference tables.
     *
     * ## OPTIONS
     *
     * [--refresh]
     */
    public function reference(array $args, array $assoc): void
    {
        $reference = $this->plugin()->reference();
        $counts = isset($assoc['refresh']) ? $reference->refresh(true) : $reference->counts();

        foreach ($counts as $table => $count) {
            if ($table === 'errors') {
                foreach ((array) $count as $failed => $message) {
                    WP_CLI::warning("$failed: $message");
                }
                continue;
            }
            WP_CLI::log(sprintf('%-34s %d', $table, (int) $count));
        }
        WP_CLI::success('reference cache age: ' . (time() - $reference->updated_at()) . 's');
    }

    /**
     * Call budget.
     *
     * ## OPTIONS
     *
     * [--reset]
     */
    public function budget(array $args, array $assoc): void
    {
        $budget = $this->plugin()->budget();
        if (isset($assoc['reset'])) {
            $budget->reset();
            WP_CLI::success('budget counter reset');
            return;
        }
        WP_CLI::log(sprintf(
            'used %d of %d, window resets in %ds, blocked for %ds',
            $budget->used(),
            $budget->limit(),
            $budget->window_resets_in(),
            $budget->blocked_for()
        ));
    }

    /**
     * Deletes old log rows.
     *
     * ## OPTIONS
     *
     * [--days=<n>]
     */
    public function purge_logs(array $args, array $assoc): void
    {
        $days = (int) ($assoc['days'] ?? $this->plugin()->settings()->int('log_retention_days', 30));
        $deleted = $this->plugin()->logger()->purge($days);
        WP_CLI::success("deleted $deleted log row(s) older than $days day(s)");
    }

    /* ------------------------------------------------------------------ */

    /** @param array<string,mixed> $result */
    private function print_result(int $yfId, array $result): void
    {
        WP_CLI::log(sprintf('yacht %d: %s — %s', $yfId, (string) $result['status'], (string) $result['message']));

        $diff = is_array($result['diff'] ?? null) ? $result['diff'] : [];
        if ($diff === []) {
            return;
        }

        $changed = array_values(array_filter($diff, static fn(array $row): bool => !empty($row['changed'])));
        $skipped = array_values(array_filter($diff, static fn(array $row): bool => !empty($row['skipped'])));

        if ($changed !== []) {
            WP_CLI::log('');
            WP_CLI::log('would change:');
            Utils\format_items('table', $changed, ['key', 'owner', 'old', 'new']);
        }
        if ($skipped !== []) {
            WP_CLI::log('');
            WP_CLI::log('left alone:');
            Utils\format_items('table', $skipped, ['key', 'owner', 'reason']);
        }
    }

    private function term_shape(string $taxonomy, string $name): string
    {
        return match (true) {
            $taxonomy === 'yacht-guests' && preg_match('/^\d+\s+guests?$/', $name) === 1 => 'exact',
            $taxonomy === 'yacht-guests' && preg_match('/^\d+\s*-\s*\d+/', $name) === 1  => 'legacy range',
            $taxonomy === 'yacht-cabins' && preg_match('/^\d+\s+cabins?$/', $name) === 1 => 'exact',
            $taxonomy === 'yacht-cabins' && preg_match('/^\d+$/', $name) === 1            => 'malformed',
            default => '',
        };
    }
}
