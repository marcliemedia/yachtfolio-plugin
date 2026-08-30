<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Sync;

/**
 * One row per sync pass, so a run interrupted mid-batch can be resumed and
 * reported instead of being reconstructed from log lines.
 */
final class RunStore
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'oy_yf_run';
    }

    public function start(string $mode, bool $dryRun, string $trigger): string
    {
        global $wpdb;

        $runId = substr(str_replace('-', '', wp_generate_uuid4()), 0, 32);
        $wpdb->insert(self::table(), [
            'run_id'         => $runId,
            'mode'           => $mode,
            'dry_run'        => $dryRun ? 1 : 0,
            'trigger_source' => substr($trigger, 0, 16),
            'started_at'     => current_time('mysql', true),
        ], ['%s', '%s', '%d', '%s', '%s']);

        return $runId;
    }

    /** @param array<string,int> $deltas */
    public function bump(string $runId, array $deltas): void
    {
        global $wpdb;

        $allowed = ['created', 'updated', 'skipped', 'errors', 'attention', 'api_calls', 'media_calls'];
        $sets = [];
        foreach ($deltas as $col => $n) {
            if (in_array($col, $allowed, true) && (int) $n !== 0) {
                $sets[] = sprintf('%s = %s + %d', $col, $col, (int) $n);
            }
        }
        if ($sets === []) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET ' . implode(', ', $sets) . ' WHERE run_id = %s',
            $runId
        ));
    }

    /** @param array<string,mixed> $summary */
    public function finish(string $runId, array $summary = []): void
    {
        global $wpdb;
        $wpdb->update(self::table(), [
            'finished_at' => current_time('mysql', true),
            'summary'     => $summary === [] ? null : wp_json_encode($summary),
        ], ['run_id' => $runId]);
    }

    /** @return array<string,mixed>|null */
    public function get(string $runId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE run_id = %s', $runId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function latest(): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row('SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 1', ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 20): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** Runs that started but never finished — used to resume or to warn. */
    public function unfinished(): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row('SELECT * FROM ' . self::table() . ' WHERE finished_at IS NULL ORDER BY id DESC LIMIT 1', ARRAY_A);
        return is_array($row) ? $row : null;
    }
}
