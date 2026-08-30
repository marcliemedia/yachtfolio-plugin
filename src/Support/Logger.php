<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Support;

final class Logger
{
    public const LEVELS = ['debug', 'info', 'warn', 'error'];
    public const STAGES = ['list', 'bulk', 'brochure', 'media', 'map', 'write', 'run', 'link'];

    private string $runId = '';

    public function __construct(private ?int $retentionDays = null)
    {
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'oy_yf_log';
    }

    public function set_run(string $runId): void
    {
        $this->runId = $runId;
    }

    public function run_id(): string
    {
        return $this->runId;
    }

    /** @param array<string,mixed> $context */
    public function debug(string $stage, string $message, array $context = [], ?int $yfId = null): void
    {
        $this->log('debug', $stage, $message, $context, $yfId);
    }

    /** @param array<string,mixed> $context */
    public function info(string $stage, string $message, array $context = [], ?int $yfId = null): void
    {
        $this->log('info', $stage, $message, $context, $yfId);
    }

    /** @param array<string,mixed> $context */
    public function warn(string $stage, string $message, array $context = [], ?int $yfId = null): void
    {
        $this->log('warn', $stage, $message, $context, $yfId);
    }

    /** @param array<string,mixed> $context */
    public function error(string $stage, string $message, array $context = [], ?int $yfId = null): void
    {
        $this->log('error', $stage, $message, $context, $yfId);
    }

    /** @param array<string,mixed> $context */
    public function log(string $level, string $stage, string $message, array $context = [], ?int $yfId = null): void
    {
        global $wpdb;

        $level = in_array($level, self::LEVELS, true) ? $level : 'info';
        $payload = Secrets::scrub($context);

        $wpdb->insert(
            self::table(),
            [
                'run_id'     => $this->runId,
                'yf_id'      => $yfId,
                'level'      => $level,
                'stage'      => substr($stage, 0, 12),
                'message'    => (string) Secrets::scrub($message),
                'context'    => $payload === [] ? null : wp_json_encode($payload),
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * @param array{run_id?:string,level?:string,stage?:string,yf_id?:int,limit?:int,offset?:int,search?:string} $args
     * @return array<int,array<string,mixed>>
     */
    public function query(array $args = []): array
    {
        global $wpdb;

        $where = ['1=1'];
        $params = [];
        foreach (['run_id' => '%s', 'level' => '%s', 'stage' => '%s'] as $col => $fmt) {
            if (!empty($args[$col])) {
                $where[] = "$col = $fmt";
                $params[] = $args[$col];
            }
        }
        if (!empty($args['yf_id'])) {
            $where[] = 'yf_id = %d';
            $params[] = (int) $args['yf_id'];
        }
        if (!empty($args['search'])) {
            $where[] = 'message LIKE %s';
            $params[] = '%' . $wpdb->esc_like((string) $args['search']) . '%';
        }

        $limit  = max(1, min(1000, (int) ($args['limit'] ?? 100)));
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $params === []
            ? $wpdb->get_results($sql, ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function count(array $args = []): int
    {
        global $wpdb;
        $where = ['1=1'];
        $params = [];
        foreach (['run_id' => '%s', 'level' => '%s', 'stage' => '%s'] as $col => $fmt) {
            if (!empty($args[$col])) {
                $where[] = "$col = $fmt";
                $params[] = $args[$col];
            }
        }
        $sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . implode(' AND ', $where);
        return (int) ($params === [] ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, ...$params)));
    }

    public function purge(?int $days = null): int
    {
        global $wpdb;
        $days = $days ?? (int) ($this->retentionDays ?? 30);
        if ($days <= 0) {
            return 0;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        return (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE created_at < %s', $cutoff));
    }
}
