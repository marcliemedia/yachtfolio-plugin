<?php

declare(strict_types=1);

namespace Otium\Yachtfolio;

use Otium\Yachtfolio\Support\Settings;

final class Activator
{
    public const CAPABILITY = 'manage_yachtfolio';

    public static function activate(): void
    {
        self::create_tables();
        self::seed_settings();
        self::grant_capability();
        update_option('oy_yf_db_version', OY_YF_VERSION, false);
    }

    public static function deactivate(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('oy_yf_index');
            as_unschedule_all_actions('oy_yf_sync_yacht');
            as_unschedule_all_actions('oy_yf_media');
            as_unschedule_all_actions('oy_yf_finalize');
        }
    }

    /** Runs on activation and on every version bump. */
    public static function maybe_upgrade(): void
    {
        if (get_option('oy_yf_db_version') !== OY_YF_VERSION) {
            self::create_tables();
            self::grant_capability();
            self::backfill_data_score();
            update_option('oy_yf_db_version', OY_YF_VERSION, false);
        }
    }

    /**
     * `data_score` is a new column, so every yacht imported before it existed
     * would read as 0 — sorting and the "incomplete" filter would be wrong until
     * the next full sync. Only linked rows are touched, so this is bounded by
     * the number of yachts actually imported, not by the size of the feed.
     */
    private static function backfill_data_score(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'oy_yf_map';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return;
        }
        if ($wpdb->get_var("SHOW COLUMNS FROM $table LIKE 'data_score'") === null) {
            return;
        }

        $rows = $wpdb->get_results("SELECT yf_id, post_id FROM $table WHERE post_id IS NOT NULL", ARRAY_A);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $wpdb->update(
                $table,
                ['data_score' => \Otium\Yachtfolio\Write\DataScore::of((int) $row['post_id'])],
                ['yf_id' => (int) $row['yf_id']]
            );
        }
    }

    public static function create_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        $sql = [];

        $sql[] = "CREATE TABLE {$p}oy_yf_map (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            yf_id BIGINT UNSIGNED NOT NULL,
            post_id BIGINT UNSIGNED NULL DEFAULT NULL,
            yacht_name VARCHAR(191) NOT NULL DEFAULT '',
            registry_port VARCHAR(191) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'unlinked',
            selected TINYINT(1) NOT NULL DEFAULT 0,
            owned TINYINT(1) NOT NULL DEFAULT 0,
            last_modified_remote VARCHAR(32) NOT NULL DEFAULT '',
            payload_hash CHAR(64) NOT NULL DEFAULT '',
            brochure_hash CHAR(64) NOT NULL DEFAULT '',
            image_count INT NOT NULL DEFAULT 0,
            media_total INT NOT NULL DEFAULT 0,
            data_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            attention VARCHAR(255) NOT NULL DEFAULT '',
            last_error TEXT NULL,
            last_synced_at DATETIME NULL DEFAULT NULL,
            last_seen_at DATETIME NULL DEFAULT NULL,
            authorisation_lost_at DATETIME NULL DEFAULT NULL,
            authorisation_last_ok_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY yf_id (yf_id),
            KEY post_id (post_id),
            KEY status (status),
            KEY selected (selected),
            KEY data_score (data_score),
            KEY authorisation_lost_at (authorisation_lost_at)
        ) $charset";

        $sql[] = "CREATE TABLE {$p}oy_yf_run (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id CHAR(32) NOT NULL,
            mode VARCHAR(8) NOT NULL DEFAULT 'live',
            dry_run TINYINT(1) NOT NULL DEFAULT 0,
            trigger_source VARCHAR(16) NOT NULL DEFAULT 'manual',
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL DEFAULT NULL,
            created INT NOT NULL DEFAULT 0,
            updated INT NOT NULL DEFAULT 0,
            skipped INT NOT NULL DEFAULT 0,
            errors INT NOT NULL DEFAULT 0,
            attention INT NOT NULL DEFAULT 0,
            api_calls INT NOT NULL DEFAULT 0,
            media_calls INT NOT NULL DEFAULT 0,
            summary LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY run_id (run_id),
            KEY started_at (started_at)
        ) $charset";

        $sql[] = "CREATE TABLE {$p}oy_yf_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id CHAR(32) NOT NULL DEFAULT '',
            yf_id BIGINT UNSIGNED NULL DEFAULT NULL,
            level VARCHAR(8) NOT NULL DEFAULT 'info',
            stage VARCHAR(12) NOT NULL DEFAULT 'run',
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY run_id (run_id),
            KEY yf_id (yf_id),
            KEY level (level),
            KEY created_at (created_at)
        ) $charset";

        $sql[] = "CREATE TABLE {$p}oy_yf_media (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_file VARCHAR(64) NOT NULL,
            attachment_id BIGINT UNSIGNED NOT NULL,
            yf_id BIGINT UNSIGNED NOT NULL,
            gallery_type VARCHAR(16) NOT NULL DEFAULT '',
            filename VARCHAR(255) NOT NULL DEFAULT '',
            real_ext VARCHAR(8) NOT NULL DEFAULT '',
            bytes INT UNSIGNED NOT NULL DEFAULT 0,
            imported_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY id_file (id_file),
            KEY yf_id (yf_id),
            KEY attachment_id (attachment_id)
        ) $charset";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }

    private static function seed_settings(): void
    {
        if (get_option(Settings::OPTION) === false) {
            add_option(Settings::OPTION, Settings::defaults(), '', false);
        }
    }

    private static function grant_capability(): void
    {
        foreach (['administrator'] as $roleName) {
            $role = get_role($roleName);
            if ($role && !$role->has_cap(self::CAPABILITY)) {
                $role->add_cap(self::CAPABILITY);
            }
        }
    }
}
