<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Sync;

/**
 * Access layer for {prefix}oy_yf_map — one row per yacht the feed offers.
 *
 * Status vocabulary: unlinked | selected | pending | synced | error | stale.
 * "selected" and "yf_visible" are curation flags: the index is cheap (1 call
 * for 433 yachts), detail and media are not, so only chosen yachts get fetched.
 */
final class YachtMapStore
{
    public const STATUS_UNLINKED = 'unlinked';
    public const STATUS_SELECTED = 'selected';
    public const STATUS_PENDING  = 'pending';
    public const STATUS_SYNCED   = 'synced';
    public const STATUS_ERROR    = 'error';
    public const STATUS_STALE    = 'stale';

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'oy_yf_map';
    }

    /**
     * Upsert index rows from type=list.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{created:int,updated:int,changed:int}
     */
    public function upsert_index(array $rows): array
    {
        global $wpdb;

        $created = $updated = $changed = 0;
        $now = current_time('mysql', true);

        foreach ($rows as $row) {
            $yfId = (int) ($row['id'] ?? 0);
            if ($yfId <= 0) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            $port = (string) ($row['registry_port'] ?? '');
            $lm   = (string) ($row['last_modified'] ?? '');

            $existing = $this->get($yfId);
            if ($existing === null) {
                $wpdb->insert(self::table(), [
                    'yf_id'                => $yfId,
                    'yacht_name'           => $name,
                    'registry_port'        => $port,
                    'status'               => self::STATUS_UNLINKED,
                    'last_modified_remote' => $lm,
                    'last_seen_at'         => $now,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
                $created++;
                continue;
            }

            $patch = ['last_seen_at' => $now];
            if ($existing['yacht_name'] !== $name) {
                $patch['yacht_name'] = $name;
            }
            if ($existing['registry_port'] !== $port) {
                $patch['registry_port'] = $port;
            }
            if ($lm !== '' && $existing['last_modified_remote'] !== $lm) {
                $patch['last_modified_remote'] = $lm;
                $changed++;
            }
            if ($existing['status'] === self::STATUS_STALE) {
                $patch['status'] = $existing['post_id'] ? self::STATUS_PENDING : self::STATUS_UNLINKED;
            }
            $this->update($yfId, $patch);
            $updated++;
        }

        return ['created' => $created, 'updated' => $updated, 'changed' => $changed];
    }

    /** @return array<string,mixed>|null */
    public function get(int $yfId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE yf_id = %d', $yfId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function by_post(int $postId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE post_id = %d', $postId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $patch */
    public function update(int $yfId, array $patch): void
    {
        global $wpdb;
        if ($patch === []) {
            return;
        }
        $patch['updated_at'] = current_time('mysql', true);
        $wpdb->update(self::table(), $patch, ['yf_id' => $yfId]);
    }

    public function set_status(int $yfId, string $status, ?string $error = null): void
    {
        $patch = ['status' => $status, 'last_error' => $error];
        if ($status === self::STATUS_SYNCED) {
            $patch['last_synced_at'] = current_time('mysql', true);
            $patch['last_error'] = null;
        }
        $this->update($yfId, $patch);
    }

    public function link(int $yfId, int $postId): void
    {
        $this->update($yfId, [
            'post_id'  => $postId,
            'selected' => 1,
            'status'   => self::STATUS_PENDING,
        ]);
        update_post_meta($postId, 'yf_id', $yfId);
        update_post_meta($postId, 'yf_sync_status', self::STATUS_PENDING);
    }

    public function unlink(int $yfId): void
    {
        $row = $this->get($yfId);
        if ($row && $row['post_id']) {
            delete_post_meta((int) $row['post_id'], 'yf_id');
            update_post_meta((int) $row['post_id'], 'yf_sync_status', self::STATUS_UNLINKED);
        }
        $this->update($yfId, ['post_id' => null, 'status' => self::STATUS_UNLINKED, 'selected' => 0]);
    }

    public function set_selected(int $yfId, bool $selected): void
    {
        $this->update($yfId, ['selected' => $selected ? 1 : 0]);
    }

    /**
     * @param array{status?:string,selected?:bool,owned?:bool,linked?:bool,attention?:bool,incomplete?:bool,search?:string,orderby?:string,order?:string,limit?:int,offset?:int} $args
     * @return array<int,array<string,mixed>>
     */
    public function all(array $args = []): array
    {
        global $wpdb;

        [$where, $params] = $this->build_where($args);

        $allowed = ['yf_id', 'yacht_name', 'status', 'last_modified_remote', 'last_synced_at', 'image_count', 'post_id', 'data_score'];
        $orderby = in_array($args['orderby'] ?? '', $allowed, true) ? $args['orderby'] : 'yacht_name';
        $order   = strtoupper($args['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $limit   = max(1, min(1000, (int) ($args['limit'] ?? 100)));
        $offset  = max(0, (int) ($args['offset'] ?? 0));

        $sql = 'SELECT * FROM ' . self::table() . " WHERE $where ORDER BY $orderby $order LIMIT $limit OFFSET $offset";
        $rows = $params === [] ? $wpdb->get_results($sql, ARRAY_A) : $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function count(array $args = []): int
    {
        global $wpdb;
        [$where, $params] = $this->build_where($args);
        $sql = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE $where";
        return (int) ($params === [] ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, ...$params)));
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function build_where(array $args): array
    {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = (string) $args['status'];
        }
        if (isset($args['selected'])) {
            $where[] = 'selected = %d';
            $params[] = $args['selected'] ? 1 : 0;
        }
        if (isset($args['owned'])) {
            $where[] = 'owned = %d';
            $params[] = $args['owned'] ? 1 : 0;
        }
        if (isset($args['linked'])) {
            $where[] = $args['linked'] ? 'post_id IS NOT NULL' : 'post_id IS NULL';
        }
        if (!empty($args['attention'])) {
            $where[] = "attention <> ''";
        }
        // Imported but missing at least one of the sections DataScore counts.
        // Unlinked rows are excluded on purpose: "not imported yet" is a
        // different problem from "imported and came back half empty".
        if (!empty($args['incomplete'])) {
            $where[] = 'post_id IS NOT NULL AND data_score < %d';
            $params[] = \Otium\Yachtfolio\Write\DataScore::MAX;
        }
        if (!empty($args['search'])) {
            $where[] = '(yacht_name LIKE %s OR yf_id = %d)';
            $params[] = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $params[] = (int) $args['search'];
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Candidate rows for a detail fetch: curation scope only.
     *
     * Freshness ("did last_modified move since the value we synced with?") is
     * decided by the orchestrator against the post's yf_last_modified, because
     * that is the timestamp we actually wrote with — not a column here.
     *
     * @return array<int,array<string,mixed>>
     */
    public function due_for_detail(bool $allScope, int $limit = 0): array
    {
        global $wpdb;

        $scope = $allScope ? '1=1' : '(selected = 1 OR post_id IS NOT NULL)';

        $sql = 'SELECT * FROM ' . self::table()
            . " WHERE $scope AND status <> '" . self::STATUS_STALE . "'"
            . ' ORDER BY (post_id IS NULL) ASC, yacht_name ASC';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @param array<int,int> $seenIds */
    public function mark_missing_stale(array $seenIds): int
    {
        global $wpdb;
        if ($seenIds === []) {
            return 0;
        }
        $in = implode(',', array_map('intval', $seenIds));
        return (int) $wpdb->query(
            "UPDATE " . self::table() . " SET status = '" . self::STATUS_STALE . "', updated_at = UTC_TIMESTAMP()
             WHERE yf_id NOT IN ($in) AND status <> '" . self::STATUS_STALE . "'"
        );
    }

    /** @return array<string,int> */
    public function status_counts(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT status, COUNT(*) c FROM ' . self::table() . ' GROUP BY status', ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }
        return $out;
    }

    public function set_attention(int $yfId, array $flags): void
    {
        $this->update($yfId, ['attention' => implode(',', array_unique(array_filter($flags)))]);
    }

    /* ------------------------------------------------------------------ *
     * Authorisation tracking
     *
     * Yachtfolio withdraws a per-yacht authorisation without any signal: the
     * detail call answers HTTP 200, errors:[] and zero rows. Nine yachts were
     * authorised on 2026-08-29 and five on 2026-08-30, and nothing in the
     * system noticed. These helpers turn that silence into a visible state.
     *
     * Rules: data already written is NEVER removed, and the post is NEVER
     * unpublished automatically — a human decides whether to pull a listing.
     * ------------------------------------------------------------------ */

    public const ATTENTION_AUTH_LOST = 'authorisation lost';

    /** @return array<int,string> */
    public static function split_flags(?string $csv): array
    {
        $parts = preg_split('/\s*,\s*/', (string) $csv) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $f): bool => $f !== ''));
    }

    /**
     * Flag a yacht whose authorisation disappeared.
     *
     * Idempotent: authorisation_lost_at keeps the FIRST observation, so the UI
     * can say "lost 3 days ago" instead of "lost just now" on every run.
     */
    /**
     * Yachtfolio has TWO independent permission scopes, verified live 2026-08-30:
     *
     *   api_brochure.cgi   — galleries, crew, video, key features, description
     *   type=yachts        — the 89-field structured record: equipment ids,
     *                        rate rows, operating areas, licences, requests
     *
     * OMNIA, MAIA, SON DE MAR and ORIY still return a full brochure while
     * type=yachts answers with zero rows. So the earlier reading of "9 yachts
     * authorised yesterday, 5 today" was wrong: nothing was revoked wholesale,
     * the structured record simply is not granted for those four. That is still
     * a real operational problem — rates, amenities and cruising areas can no
     * longer be refreshed for them — so it gets its own visible state instead
     * of being lumped in with a total loss.
     */
    public const ATTENTION_DETAIL_LOST = 'detail record unavailable';

    /** @return bool true when the flag was NOT already present */
    private function add_flag(int $yfId, string $flag, string $detail, bool $clearOwned): bool
    {
        $row = $this->get($yfId);
        if ($row === null) {
            return false;
        }

        $flags = self::split_flags($row['attention'] ?? '');
        $already = in_array($flag, $flags, true);
        if (!$already) {
            $flags[] = $flag;
        }

        $patch = ['attention' => implode(',', array_unique($flags))];
        if ($clearOwned) {
            $patch['owned'] = 0;
        }
        if ($detail !== '') {
            $patch['last_error'] = $detail;
        }
        if (empty($row['authorisation_lost_at'])) {
            $patch['authorisation_lost_at'] = current_time('mysql', true);
            if (empty($row['authorisation_last_ok_at']) && !empty($row['last_synced_at'])) {
                // Best available answer to "when did we last really have it?"
                $patch['authorisation_last_ok_at'] = $row['last_synced_at'];
            }
        }

        $this->update($yfId, $patch);
        return !$already;
    }

    /** @return bool true when the flag WAS present, i.e. this is a recovery */
    private function drop_flag(int $yfId, string $flag, bool $clearTimestamps): bool
    {
        $row = $this->get($yfId);
        if ($row === null) {
            return false;
        }

        $flags = self::split_flags($row['attention'] ?? '');
        $had = in_array($flag, $flags, true);
        $flags = array_values(array_filter($flags, static fn(string $f): bool => $f !== $flag));

        $patch = ['attention' => implode(',', $flags)];
        if ($clearTimestamps) {
            // authorisation_last_ok_at means "the structured detail record was
            // available at this moment". Only the detail-scope clear may move
            // it; a brochure-scope recovery must not claim the detail is back.
            $patch['authorisation_lost_at'] = null;
            $patch['authorisation_last_ok_at'] = current_time('mysql', true);
        }

        $this->update($yfId, $patch);
        return $had;
    }

    /**
     * Total loss: neither the brochure nor the structured record answers, while
     * the yacht is still listed in the index. Data already written is kept and
     * the post is never unpublished — a human decides whether to pull it.
     */
    public function flag_authorisation_lost(int $yfId, string $detail = ''): bool
    {
        $new = $this->add_flag(
            $yfId,
            self::ATTENTION_AUTH_LOST,
            $detail !== '' ? $detail : 'authorisation withdrawn: every detail call returned zero rows with no error',
            true
        );
        $row = $this->get($yfId);
        if ($row !== null && !empty($row['post_id'])) {
            update_post_meta((int) $row['post_id'], 'yf_authorisation_lost', '1');
        }
        return $new;
    }

    public function clear_authorisation_lost(int $yfId): bool
    {
        // false: recovering brochure access says nothing about the detail scope.
        $had = $this->drop_flag($yfId, self::ATTENTION_AUTH_LOST, false);
        $row = $this->get($yfId);
        if ($had && $row !== null && !empty($row['post_id'])) {
            delete_post_meta((int) $row['post_id'], 'yf_authorisation_lost');
        }
        return $had;
    }

    /**
     * Partial loss: the brochure still works but type=yachts returns nothing,
     * so rates, equipment/amenities, cruising areas, licences and special
     * requests are frozen at their last synced values for this yacht.
     */
    public function flag_detail_unavailable(int $yfId, string $detail = ''): bool
    {
        $new = $this->add_flag(
            $yfId,
            self::ATTENTION_DETAIL_LOST,
            $detail !== '' ? $detail : 'type=yachts returned zero rows: rates, equipment and cruising areas cannot be refreshed',
            true
        );
        $row = $this->get($yfId);
        if ($row !== null && !empty($row['post_id'])) {
            update_post_meta((int) $row['post_id'], 'yf_detail_present', '0');
        }
        return $new;
    }

    public function clear_detail_unavailable(int $yfId): bool
    {
        $had = $this->drop_flag($yfId, self::ATTENTION_DETAIL_LOST, true);
        $row = $this->get($yfId);
        if ($row !== null && !empty($row['post_id'])) {
            update_post_meta((int) $row['post_id'], 'yf_detail_present', '1');
        }
        return $had;
    }

    /**
     * Reconcile detail availability for every linked, already-synced yacht.
     *
     * Deliberately a STANDING-STATE sweep, not a 1->0 transition check: the
     * four affected yachts had already slipped to owned=0 before any of this
     * existed, and a transition check would never have noticed them. Comparing
     * the world against the current owned set each run cannot miss a yacht.
     *
     * @param array<int,int> $ownedIds ids the feed just returned detail for
     * @return array{flagged:array<int,int>,cleared:array<int,int>}
     */
    public function sweep_detail_availability(array $ownedIds): array
    {
        global $wpdb;

        $flagged = [];
        $cleared = [];

        $rows = $wpdb->get_results(
            'SELECT yf_id, payload_hash, status FROM ' . self::table() . ' WHERE post_id IS NOT NULL',
            ARRAY_A
        );

        foreach (is_array($rows) ? $rows : [] as $row) {
            $yfId = (int) $row['yf_id'];
            $everSynced = (string) $row['payload_hash'] !== '' || (string) $row['status'] === self::STATUS_SYNCED;
            if (!$everSynced) {
                continue; // never had a detail record, so none was lost
            }
            if (in_array($yfId, $ownedIds, true)) {
                if ($this->clear_detail_unavailable($yfId)) {
                    $cleared[] = $yfId;
                }
            } elseif ($this->flag_detail_unavailable($yfId)) {
                $flagged[] = $yfId;
            }
        }

        return ['flagged' => $flagged, 'cleared' => $cleared];
    }

    public function count_detail_unavailable(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::table()
            . " WHERE attention LIKE '%" . $wpdb->esc_like(self::ATTENTION_DETAIL_LOST) . "%'"
        );
    }

    /**
     * Counted by FLAG, not by authorisation_lost_at: that timestamp is shared
     * by both loss states, so keying off it reported every detail-only problem
     * as a total blackout and made the admin notice cry wolf.
     */
    public function count_authorisation_lost(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::table()
            . " WHERE attention LIKE '%" . $wpdb->esc_like(self::ATTENTION_AUTH_LOST) . "%'"
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function authorisation_lost(int $limit = 100): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . self::table()
            . " WHERE attention LIKE '%" . $wpdb->esc_like(self::ATTENTION_AUTH_LOST) . "%'"
            . ' ORDER BY authorisation_lost_at ASC LIMIT ' . max(1, min(500, $limit)),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    /**
     * Reconcile the owned flag against a successful bulk call.
     *
     * Only ever called with a NON-EMPTY owned set: a bulk call that returns
     * nothing is far more likely to be a transient API fault than every single
     * authorisation vanishing at once, and clearing the flag wholesale would
     * mass-flag the fleet as lost.
     *
     * @param array<int,int> $ownedIds
     * @return array<int,int> yf_ids that just lost the owned flag
     */
    public function reconcile_owned(array $ownedIds): array
    {
        global $wpdb;

        if ($ownedIds === []) {
            return [];
        }

        $in = implode(',', array_map('intval', $ownedIds));

        $lost = $wpdb->get_col(
            'SELECT yf_id FROM ' . self::table() . " WHERE owned = 1 AND yf_id NOT IN ($in)"
        );
        $lost = array_map('intval', is_array($lost) ? $lost : []);

        if ($lost !== []) {
            $wpdb->query(
                'UPDATE ' . self::table() . " SET owned = 0, updated_at = UTC_TIMESTAMP() WHERE owned = 1 AND yf_id NOT IN ($in)"
            );
        }

        $wpdb->query(
            'UPDATE ' . self::table() . " SET owned = 1, authorisation_last_ok_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE yf_id IN ($in)"
        );

        return $lost;
    }
}
