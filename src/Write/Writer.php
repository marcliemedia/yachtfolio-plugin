<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Write;

use Otium\Yachtfolio\Domain\Hasher;
use Otium\Yachtfolio\Domain\YachtPayload;
use Otium\Yachtfolio\Mapping\AmenityText;
use Otium\Yachtfolio\Mapping\EquipmentMap;
use Otium\Yachtfolio\Mapping\MapResult;
use Otium\Yachtfolio\Support\Logger;
use Otium\Yachtfolio\Support\Secrets;
use Otium\Yachtfolio\Support\Settings;
use Otium\Yachtfolio\Sync\YachtMapStore;

/**
 * The only class that writes yacht content.
 *
 * Invariants:
 *  - a new yacht is always created as `draft`; publishing is a human action
 *  - an update never carries post_status or post_name
 *  - addon-only fields are never written
 *  - a value edited in WordPress is never replaced; Yachtfolio only updates
 *    fields nobody has touched since it last wrote them, and refills fields
 *    an editor emptied (EditTracker, client decision 2026-10-20). On
 *    hand-entered yachts that means: only empty fields are filled
 *    (2026-08-30 mandate)
 *  - plan() and apply() both call decide(), so a dry run cannot drift from a
 *    real write — they are literally the same computation
 */
final class Writer
{
    private const RAW_GZIP_THRESHOLD = 262144; // 256 KB

    /**
     * The rate boxes the yacht page prints, one row each. Yachtfolio pre-fills
     * High and Low; Shoulder, Summer and Winter are typed in WordPress only.
     */
    private const RATE_BOXES = [
        'high_season_rate',
        'low_season_rate',
        'shoulder_season_rate',
        'summer_season_rate',
        'winter_season_rate',
    ];

    public const MODE_FILL_EMPTY   = 'fill_empty_only';
    public const MODE_AUTHORITATIVE = 'feed_authoritative';

    /** @var array<string,mixed> */
    private array $lastReport = [];

    public function __construct(
        private Settings $settings,
        private Logger $log
    ) {
    }

    /** @return array<int,FieldDiff> */
    public function plan(YachtPayload $payload, MapResult $map, ?int $postId): array
    {
        $decision = $this->decide($payload, $map, $postId);
        return $decision['diffs'];
    }

    /**
     * Everything plan() and apply() need, computed once.
     *
     * @return array{
     *   diffs:array<int,FieldDiff>, origin:string, protect:bool, append_terms:bool,
     *   post:array<string,mixed>, meta:array<string,mixed>, terms:array<string,array<int,string>>,
     *   protected:array<string,array{old:string,new:string}>, rejected:array<string,string>,
     *   snapshot:array<string,string>, held:array<int,string>, feed_changed:array<int,string>
     * }
     */
    private function decide(YachtPayload $payload, MapResult $map, ?int $postId): array
    {
        $mappedAmenities = Ownership::api_owned_amenity_keys();
        $post = $postId !== null && $postId > 0 ? get_post($postId) : null;

        $origin  = Ownership::origin_of($post ? (int) $post->ID : null);
        $manual  = $post !== null && $origin === Ownership::ORIGIN_MANUAL;
        $mode    = (string) $this->settings->get('write_mode', self::MODE_FILL_EMPTY);
        $authoritative = $mode === self::MODE_AUTHORITATIVE;
        // Terms: hand-entered yachts only ever gain terms.
        $protect = $manual && !$authoritative;

        $snapshot    = $post !== null ? EditTracker::snapshot($post->ID) : [];
        $newSnapshot = $snapshot;
        $wasChanged  = $post !== null ? EditTracker::feed_changed($post->ID) : [];
        $feedChanged = [];

        $diffs      = [];
        $writePost  = [];
        $writeMeta  = [];
        $protected  = [];

        $reasonEdited = 'edited in WordPress — kept (tick it under "Edited in WordPress" to take the Yachtfolio value)';
        $wasHeld = $post !== null ? EditTracker::held($post->ID) : [];

        /**
         * One rule for every API-owned field, post fields and meta alike.
         * Returns true when the target value must be written.
         */
        $judge = function (string $field, mixed $current, mixed $target, string $owner) use (
            $post, $manual, $authoritative, $snapshot, &$newSnapshot, &$feedChanged, $wasChanged, $wasHeld,
            &$diffs, &$protected, $reasonEdited
        ): bool {
            $curN = EditTracker::normalise($field, $current);
            $newN = EditTracker::normalise($field, $target);
            $changed = $curN !== $newN;

            if ($owner !== 'api') {
                // Infra (yf_*) is plumbing and must stay accurate or change detection breaks.
                $exact = $this->comparable($current) !== $this->comparable($target);
                $diffs[] = new FieldDiff($field, $current, $target, $owner, $exact);
                return $exact;
            }

            $offered = md5($newN);
            $curBlank = Ownership::is_blank($field, $curN);
            $known = isset($snapshot[$field]);

            // Empty and never emptied by anyone: Yachtfolio pre-fills it. An
            // empty field the admin cleared (fingerprint of a value, or a held
            // hand-entered field) is an edit like any other and stays empty.
            $prefill = $curBlank && ($known
                ? in_array($snapshot[$field], [md5(''), md5('0')], true)
                : !in_array($field, $wasHeld, true));
            if ($post === null || $authoritative || $prefill) {
                $newSnapshot[$field] = $offered;
                $diffs[] = new FieldDiff($field, $current, $target, $owner, $changed, false, $curBlank && $changed && $post !== null ? 'empty field filled from Yachtfolio' : '');
                return $changed;
            }

            $untouched = $known ? md5($curN) === $snapshot[$field] : (!$curBlank && !$changed && !$manual);
            if ($untouched) {
                if ($changed && Ownership::is_blank($field, $newN)) {
                    // A blank feed value never erases stored content.
                    $diffs[] = new FieldDiff($field, $current, $target, $owner, false, true, 'feed value empty, existing content kept');
                    return false;
                }
                $newSnapshot[$field] = $offered;
                $diffs[] = new FieldDiff($field, $current, $target, $owner, $changed);
                return $changed;
            }

            // Edited in WordPress (or typed by hand before the feed existed): kept.
            if ($known || !$manual) {
                if (($known && $snapshot[$field] !== $offered) || in_array($field, $wasChanged, true)) {
                    $feedChanged[] = $field;
                }
                $newSnapshot[$field] = $offered;
            }
            if ($changed) {
                $protected[$field] = ['old' => $curN, 'new' => $newN];
            }
            $diffs[] = new FieldDiff($field, $current, $target, $owner, false, $changed, $changed ? $reasonEdited : '');
            return false;
        };

        /* ---- post fields ---- */
        foreach (EditTracker::POST_FIELDS as $field) {
            if (!array_key_exists($field, $map->post)) {
                continue;
            }
            $target = (string) ($map->post[$field] ?? '');
            if ($judge($field, (string) ($post?->{$field} ?? ''), $target, 'api')) {
                $writePost[$field] = $target;
            }
        }

        if ($post === null) {
            $diffs[] = new FieldDiff('post_name', null, $map->post['post_name'] ?? '', 'api', true);
            $diffs[] = new FieldDiff('post_status', null, 'draft', 'infra', true, false, 'imports are always drafts');
            $diffs[] = new FieldDiff(Ownership::ORIGIN_META, null, Ownership::ORIGIN_FEED, 'infra', true, false, 'created by the importer');
        } else {
            $diffs[] = new FieldDiff('post_name', $post->post_name, $post->post_name, 'api', false, true, 'slug frozen after creation (SEO)');
            $diffs[] = new FieldDiff('post_status', $post->post_status, $post->post_status, 'infra', false, true, 'publishing is a human action');
            $diffs[] = new FieldDiff(Ownership::ORIGIN_META, $origin, $origin, 'infra', false, true, $authoritative ? 'write mode: feed authoritative' : 'write mode: edits in WordPress are kept');
        }

        /* ---- meta ---- */
        [$allowed, $rejected] = Ownership::filter($map->meta, $mappedAmenities);

        foreach ($allowed as $key => $value) {
            [, $storable] = MetaFormat::for_value($value);
            $owner   = Ownership::owner_of($key, $mappedAmenities);
            $current = $postId ? get_post_meta($postId, $key, true) : '';
            if ($judge($key, $current, $storable, $owner)) {
                $writeMeta[$key] = $storable;
            }
        }

        // Every field kept as edited, not only those the feed disagrees with
        // today: the page and the editor list read this set.
        $held = [];
        if ($post !== null && !$authoritative) {
            foreach ($diffs as $diff) {
                if ($diff->owner === 'api' && $diff->reason === $reasonEdited) {
                    $held[] = $diff->key;
                }
            }
            foreach (EditTracker::held($post->ID) as $field) {
                // Still edited, but the feed offered the same value as before
                // or nothing at all for it this time.
                if (!in_array($field, $held, true)
                    && !isset($writePost[$field]) && !isset($writeMeta[$field])
                    && (!isset($newSnapshot[$field]) || EditTracker::fingerprint($field, EditTracker::current($post->ID, $field)) !== $newSnapshot[$field])) {
                    $held[] = $field;
                }
            }
        }

        foreach ($rejected as $key => $reason) {
            $diffs[] = new FieldDiff(
                $key,
                $postId ? get_post_meta($postId, $key, true) : null,
                $map->meta[$key] ?? null,
                Ownership::owner_of($key, $mappedAmenities),
                false,
                true,
                'editor-owned field'
            );
        }

        /* ---- taxonomies ---- */
        $writeTerms = [];
        foreach ($map->terms as $taxonomy => $names) {
            $current = $postId ? TermWriter::current($postId, $taxonomy) : [];
            $incoming = array_values(array_filter(array_map('strval', $names), static fn(string $n): bool => trim($n) !== ''));

            // Protected yachts merge; feed-owned yachts replace.
            $target = $protect ? array_values(array_unique(array_merge($current, $incoming))) : $incoming;

            $a = $current;
            $b = $target;
            sort($a);
            sort($b);

            $diffs[] = new FieldDiff(
                "tax:$taxonomy",
                implode(', ', $current),
                implode(', ', $target),
                'api',
                $a !== $b,
                false,
                $protect ? 'terms are added, never removed' : ''
            );

            if ($incoming !== []) {
                $writeTerms[$taxonomy] = $incoming;
            }
        }

        /* ---- infrastructure ---- */
        $hash = Hasher::of($payload);
        $curHash = $postId ? (string) get_post_meta($postId, 'yf_payload_hash', true) : null;
        $curLm   = $postId ? (string) get_post_meta($postId, 'yf_last_modified', true) : null;
        // Report the real state: claiming these always change made every dry run
        // read "2 fields would change" even when the payload was identical.
        $diffs[] = new FieldDiff('yf_payload_hash', $curHash, $hash, 'infra', $curHash !== $hash);
        $diffs[] = new FieldDiff('yf_last_modified', $curLm, $payload->last_modified, 'infra', $curLm !== $payload->last_modified);

        return [
            'diffs'        => $diffs,
            'origin'       => $origin,
            'protect'      => !$authoritative,
            'append_terms' => $protect,
            'post'         => $writePost,
            'meta'         => $writeMeta,
            'terms'        => $writeTerms,
            'protected'    => $protected,
            'rejected'     => $rejected,
            'snapshot'     => $newSnapshot,
            'held'         => $held,
            'feed_changed' => $feedChanged,
        ];
    }

    /**
     * @return int post ID
     * @throws \RuntimeException
     */
    public function apply(YachtPayload $payload, MapResult $map, ?int $postId): int
    {
        // The sync's own writes are not edits made in WordPress.
        EditTracker::suspend(true);
        try {
            return $this->apply_unsuspended($payload, $map, $postId);
        } finally {
            EditTracker::suspend(false);
        }
    }

    private function apply_unsuspended(YachtPayload $payload, MapResult $map, ?int $postId): int
    {
        $created = $postId === null || $postId <= 0 || !get_post($postId);

        if ($created) {
            $inserted = wp_insert_post([
                'post_type'    => 'yacht',
                'post_status'  => 'draft', // never published by the importer
                'post_title'   => $map->post['post_title'] ?? $payload->name,
                'post_content' => $map->post['post_content'] ?? '',
                'post_name'    => $map->post['post_name'] ?? sanitize_title($payload->name),
            ], true);

            if (is_wp_error($inserted)) {
                throw new \RuntimeException('wp_insert_post failed: ' . $inserted->get_error_message());
            }

            $postId = (int) $inserted;

            // Provenance, written once: this yacht came from the feed, so the
            // feed owns its content. Hand-entered yachts never get this value.
            update_post_meta($postId, Ownership::ORIGIN_META, Ownership::ORIGIN_FEED);

            if (get_post_meta($postId, 'yf_visible', true) === '') {
                // Admin-owned gate, initialised once and never touched again.
                update_post_meta($postId, 'yf_visible', MetaFormat::switcher(false));
            }
        }

        // Decided AFTER the insert so a fresh post is classified as feed-owned.
        $decision = $this->decide($payload, $map, $postId);

        if (!$created && $decision['post'] !== []) {
            $updates = ['ID' => $postId] + $decision['post'];
            // post_status and post_name are deliberately absent.
            $result = wp_update_post($updates, true);
            if (is_wp_error($result)) {
                throw new \RuntimeException('wp_update_post failed: ' . $result->get_error_message());
            }
        }

        /* ---- meta ---- */
        $written = 0;
        foreach ($decision['meta'] as $key => $storable) {
            update_post_meta($postId, $key, $storable);
            $written++;
        }

        /* ---- taxonomies ---- */
        $termWriter = new TermWriter($this->settings, $this->log);
        $termReport = $termWriter->assign($postId, $decision['terms'], null, $decision['append_terms']);

        /* ---- infrastructure meta ---- */
        update_post_meta($postId, 'yf_id', $payload->yf_id);
        update_post_meta($postId, 'yf_last_modified', $payload->last_modified);
        update_post_meta($postId, 'yf_payload_hash', Hasher::of($payload));
        update_post_meta($postId, 'yf_synced_at', gmdate('c'));
        update_post_meta($postId, 'yf_sync_status', YachtMapStore::STATUS_SYNCED);
        update_post_meta($postId, 'yf_type_derivable', MetaFormat::switcher((bool) ($map->meta['yf_type_derivable'] ?? false)));
        $this->store_raw($postId, $payload);

        // Before the presence flags, so DataScore reads this sync's state.
        EditTracker::save_snapshot($postId, $decision['snapshot']);
        EditTracker::store_state($postId, $decision['held'], $decision['feed_changed']);

        $this->store_presence_flags($postId, $map);

        $protectedKeys = array_keys($decision['protected']);

        $this->lastReport = [
            'post_id'          => $postId,
            'created'          => $created,
            'origin'           => $decision['origin'],
            'protected_mode'   => $decision['protect'],
            'meta_written'     => $written,
            'meta_protected'   => count($protectedKeys),
            'protected_fields' => $protectedKeys,
            'post_fields'      => array_keys($decision['post']),
            'terms_appended'   => $decision['append_terms'],
        ];

        $this->log->info('write', $created ? 'yacht created as draft' : 'yacht updated', [
            'post_id'          => $postId,
            'origin'           => $decision['origin'],
            'protected_mode'   => $decision['protect'] ? 'edits_kept' : 'feed_authoritative',
            'meta_written'     => $written,
            'meta_protected'   => count($protectedKeys),
            'protected_fields' => $protectedKeys,
            'meta_skipped'     => count($decision['rejected']),
            'terms'            => array_map('count', $termReport['assigned']),
            'terms_missing'    => $termReport['missing'],
        ], $payload->yf_id);

        if ($protectedKeys !== []) {
            $this->log->warn('write', 'feed disagreed with values edited in WordPress; kept the edited values', [
                'post_id' => $postId,
                'fields'  => $protectedKeys,
            ], $payload->yf_id);
        }

        return $postId;
    }

    /** @return array<string,mixed> report of the most recent apply() */
    public function last_report(): array
    {
        return $this->lastReport;
    }

    /**
     * "Does this yacht actually have X?" markers for the templates.
     *
     * WHY: a feed yacht arrives with holes — no equipment list unless the
     * structured detail record is granted, no gallery until the media pass has
     * run, no crew on some records. A Bricks accordion or section whose content
     * is empty still renders its header and its padding, which is exactly what
     * made the feed single page show an "Amenities" bar with nothing under it
     * and a 158 px blank band where related yachts would be.
     *
     * Bricks element conditions are easiest to write against a field that is
     * EMPTY when there is nothing, so that the existing `empty_not` idiom used
     * throughout the yacht template keeps working. A plain count would store
     * "0", and "0" is not empty. So: the count when there is something, an
     * empty string when there is not.
     */
    private function store_presence_flags(int $postId, MapResult $map): void
    {
        $countTrue = 0;
        foreach ($map->meta as $key => $value) {
            if (str_starts_with($key, 'amenities_') && ($value === true || $value === 'true')) {
                $countTrue++;
            }
        }

        $repeaterSize = static fn(mixed $v): int => count(self::as_rows($v));
        $meta = fn(string $key): mixed => $map->meta[$key] ?? get_post_meta($postId, $key, true);

        $gallery = (string) get_post_meta($postId, 'gallery', true);
        $galleryCount = $gallery === '' ? 0 : count(array_filter(array_map('trim', explode(',', $gallery))));

        $flags = [
            'yf_has_amenities' => $countTrue,
            // The rate boxes the page shows; read after the meta writes, so
            // an edited (kept) box counts as it stands. See refresh_rates_flag().
            'yf_has_rates'     => self::filled_rate_boxes($postId),
            'yf_has_crew'      => $repeaterSize($meta('yf_crew')),
            'yf_has_toys'      => $repeaterSize($meta('toys_repeater')),
            'yf_has_gallery'   => $galleryCount,
            'yf_has_licences'  => $repeaterSize($meta('yf_licences')),
            'yf_has_requests'  => $repeaterSize($meta('yf_special_requests')),
            'yf_has_unavailable' => $repeaterSize($meta('yf_seasons_unavailable')),
            // Equipment the switcher grid cannot express, so the Specifications
            // row only appears when there is genuinely something left over.
            'yf_has_equipment_extra' => count(AmenityText::unmatched(
                (string) ($map->meta['yf_equipment_list'] ?? get_post_meta($postId, 'yf_equipment_list', true))
            )),
            // The audio/video note shows only when it says something the
            // amenity switchers beside it do not. See AmenityText::coverage().
            'yf_has_av'        => (static function (string $t): int {
                return $t !== '' && AmenityText::coverage($t) < AmenityText::COVERAGE_REDUNDANT ? 1 : 0;
            })(trim((string) ($map->meta['yf_av_facilities'] ?? get_post_meta($postId, 'yf_av_facilities', true)))),
        ];

        foreach ($flags as $key => $n) {
            update_post_meta($postId, $key, $n > 0 ? (string) $n : '');
        }
    }

    /**
     * Recomputes the rate marker on its own, after a save in WordPress
     * changed the rate boxes (EditTracker::on_saved()).
     */
    public static function refresh_rates_flag(int $postId): void
    {
        $n = self::filled_rate_boxes($postId);
        update_post_meta($postId, 'yf_has_rates', $n > 0 ? (string) $n : '');
    }

    /**
     * Rate rows the yacht page prints: one per filled rate box. The feed's own
     * season rows (`yf_rates`) are stored but never shown.
     */
    private static function filled_rate_boxes(int $postId): int
    {
        $n = 0;
        foreach (self::RATE_BOXES as $key) {
            if (trim((string) get_post_meta($postId, $key, true)) !== '') {
                $n++;
            }
        }
        return $n;
    }

    /** @return array<int|string,mixed> */
    private static function as_rows(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        $un = is_string($v) ? maybe_unserialize($v) : null;
        if (is_array($un)) {
            return $un;
        }
        // A single-row repeater arrives as a bare scalar (yf_seasons_unavailable).
        return is_string($v) && trim($v) !== '' ? [$v] : [];
    }

    /**
     * Last raw payload, so adding a field later is a remap instead of a refetch.
     */
    private function store_raw(int $postId, YachtPayload $payload): void
    {
        $json = (string) wp_json_encode(Secrets::scrub($payload->raw));

        if (strlen($json) > self::RAW_GZIP_THRESHOLD && function_exists('gzencode')) {
            $packed = gzencode($json, 6);
            if (is_string($packed)) {
                // base64 has no backslashes, so it survives the unslash below.
                update_post_meta($postId, 'yf_raw', base64_encode($packed));
                update_post_meta($postId, 'yf_raw_encoding', 'gzip+base64');
                return;
            }
        }

        /**
         * wp_slash() is REQUIRED here.
         *
         * update_metadata() runs wp_unslash() on every value, a historical
         * artefact of meta arriving from $_POST already slashed. JSON uses
         * backslashes for escaping, so storing it raw silently strips them:
         *   {"length_feet":"129' 3\""}   becomes   {"length_feet":"129' 3""}
         * which is no longer parseable. The payload looked fine in the DB and
         * json_decode() returned null for every yacht, quietly defeating the
         * whole point of yf_raw — that adding a mapped field later should be a
         * remap, not a refetch of 433 yachts.
         */
        update_post_meta($postId, 'yf_raw', wp_slash($json));
        update_post_meta($postId, 'yf_raw_encoding', 'json');
    }

    /** Decodes whatever store_raw() wrote. */
    public static function read_raw(int $postId): string
    {
        $raw = (string) get_post_meta($postId, 'yf_raw', true);
        if ($raw === '') {
            return '';
        }
        if (get_post_meta($postId, 'yf_raw_encoding', true) === 'gzip+base64') {
            $packed = base64_decode($raw, true);
            if (is_string($packed) && function_exists('gzdecode')) {
                $json = @gzdecode($packed);
                if (is_string($json)) {
                    return (string) Secrets::scrub($json);
                }
            }
            return '';
        }
        return (string) Secrets::scrub($raw);
    }

    private function comparable(mixed $value): string
    {
        if (is_array($value)) {
            return (string) wp_json_encode($value);
        }
        if (is_bool($value)) {
            return MetaFormat::switcher($value);
        }
        return trim((string) $value);
    }
}
