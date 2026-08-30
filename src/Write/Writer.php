<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Write;

use Otium\Yachtfolio\Domain\Hasher;
use Otium\Yachtfolio\Domain\YachtPayload;
use Otium\Yachtfolio\Mapping\AmenityText;
use Otium\Yachtfolio\Mapping\EquipmentMap;
use Otium\Yachtfolio\Mapping\MapResult;
use Otium\Yachtfolio\Mapping\RatesTable;
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
 *  - addon-only and locked fields are never written
 *  - HAND-ENTERED yachts are protected: an existing value is never replaced,
 *    only empty fields are filled (2026-08-30 client mandate, reverses D3)
 *  - plan() and apply() both call decide(), so a dry run cannot drift from a
 *    real write — they are literally the same computation
 */
final class Writer
{
    private const RAW_GZIP_THRESHOLD = 262144; // 256 KB

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
     *   protected:array<string,array{old:string,new:string}>, rejected:array<string,string>
     * }
     */
    private function decide(YachtPayload $payload, MapResult $map, ?int $postId): array
    {
        $mappedAmenities = Ownership::api_owned_amenity_keys();
        $post = $postId !== null && $postId > 0 ? get_post($postId) : null;

        $origin  = Ownership::origin_of($post ? (int) $post->ID : null);
        $mode    = (string) $this->settings->get('write_mode', self::MODE_FILL_EMPTY);
        $protect = $post !== null
            && $origin === Ownership::ORIGIN_MANUAL
            && $mode !== self::MODE_AUTHORITATIVE;

        $diffs      = [];
        $writePost  = [];
        $writeMeta  = [];
        $protected  = [];

        $reasonProtected = 'protected: hand-entered yacht, field is not empty';

        /* ---- post fields ---- */
        foreach (['post_title', 'post_content'] as $field) {
            if (!array_key_exists($field, $map->post)) {
                continue;
            }
            $current = (string) ($post?->{$field} ?? '');
            $target  = (string) ($map->post[$field] ?? '');
            $changed = $current !== $target;

            if ($changed && $protect && !Ownership::is_blank($field, trim($current))) {
                $diffs[] = new FieldDiff($field, $current, $target, 'api', false, true, $reasonProtected);
                $protected[$field] = ['old' => $current, 'new' => $target];
                continue;
            }
            if ($changed && $target === '' && $post !== null) {
                // Never blank out an existing post field with an empty feed value.
                $diffs[] = new FieldDiff($field, $current, $target, 'api', false, true, 'feed value empty, existing content kept');
                continue;
            }
            $diffs[] = new FieldDiff($field, $current, $target, 'api', $changed);
            if ($changed) {
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
            $diffs[] = new FieldDiff(Ownership::ORIGIN_META, $origin, $origin, 'infra', false, true, $protect ? 'write mode: fill empty fields only' : 'write mode: feed authoritative');
        }

        /* ---- meta ---- */
        [$allowed, $rejected] = Ownership::filter($map->meta, (int) $postId, $mappedAmenities);

        foreach ($allowed as $key => $value) {
            [, $storable] = MetaFormat::for_value($value);
            $owner   = Ownership::owner_of($key, $mappedAmenities);
            $current = $postId ? get_post_meta($postId, $key, true) : '';
            $curCmp  = $this->comparable($current);
            $newCmp  = $this->comparable($storable);
            $changed = $curCmp !== $newCmp;

            // Protection covers API-owned display fields only. Infra (yf_*) is
            // plumbing and must stay accurate or change detection breaks.
            if ($changed && $protect && $owner === 'api' && !Ownership::is_blank($key, $curCmp)) {
                $diffs[] = new FieldDiff($key, $current, $storable, $owner, false, true, $reasonProtected);
                $protected[$key] = ['old' => $curCmp, 'new' => $newCmp];
                continue;
            }
            // A blank feed value never erases stored content.
            if ($changed && $owner === 'api' && $postId && Ownership::is_blank($key, $newCmp) && !Ownership::is_blank($key, $curCmp)) {
                $diffs[] = new FieldDiff($key, $current, $storable, $owner, false, true, 'feed value empty, existing content kept');
                continue;
            }

            $diffs[] = new FieldDiff($key, $current, $storable, $owner, $changed);
            if ($changed) {
                $writeMeta[$key] = $storable;
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
                $reason === 'locked' ? 'locked by yf_locked_fields' : 'editor-owned field'
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
            'protect'      => $protect,
            'append_terms' => $protect,
            'post'         => $writePost,
            'meta'         => $writeMeta,
            'terms'        => $writeTerms,
            'protected'    => $protected,
            'rejected'     => $rejected,
        ];
    }

    /**
     * @return int post ID
     * @throws \RuntimeException
     */
    public function apply(YachtPayload $payload, MapResult $map, ?int $postId): int
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

        $this->store_presence_flags($postId, $map);

        $protectedKeys = array_keys($decision['protected']);
        update_post_meta($postId, 'yf_protected_fields', implode(',', $protectedKeys));
        update_post_meta($postId, 'yf_protected_count', count($protectedKeys));

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
            'protected_mode'   => $decision['protect'] ? 'fill_empty_only' : 'feed_authoritative',
            'meta_written'     => $written,
            'meta_protected'   => count($protectedKeys),
            'protected_fields' => $protectedKeys,
            'meta_skipped'     => count($decision['rejected']),
            'terms'            => array_map('count', $termReport['assigned']),
            'terms_missing'    => $termReport['missing'],
        ], $payload->yf_id);

        if ($protectedKeys !== []) {
            $this->log->warn('write', 'feed disagreed with hand-entered content; kept the existing values', [
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

        $rows = static function (mixed $v): array {
            if (is_array($v)) {
                return $v;
            }
            $un = is_string($v) ? maybe_unserialize($v) : null;
            if (is_array($un)) {
                return $un;
            }
            // A single-row repeater arrives as a bare scalar (yf_seasons_unavailable).
            return is_string($v) && trim($v) !== '' ? [$v] : [];
        };
        $repeaterSize = static fn(mixed $v): int => count($rows($v));
        $meta = fn(string $key): mixed => $map->meta[$key] ?? get_post_meta($postId, $key, true);

        $gallery = (string) get_post_meta($postId, 'gallery', true);
        $galleryCount = $gallery === '' ? 0 : count(array_filter(array_map('trim', explode(',', $gallery))));

        $flags = [
            'yf_has_amenities' => $countTrue,
            // Only the seasons a customer can still book, so the badge can never
            // disagree with what {yf_rates_table} prints. See RatesTable::upcoming().
            'yf_has_rates'     => count(RatesTable::upcoming($rows($meta('yf_rates')))),
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
