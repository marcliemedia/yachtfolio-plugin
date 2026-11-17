<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Write;

use Otium\Yachtfolio\Plugin;

/**
 * What an admin types in WordPress wins — per field, with nothing to decide.
 * Yachtfolio only pre-fills; the admin may overwrite anything, including by
 * leaving a field empty (PM rule 2026-10-26, replaces option A of 2026-10-20).
 *
 * Every time the sync offers a value for a field, the fingerprint of that
 * value is kept in `yf_feed_values`. A field whose current value still
 * matches the fingerprint has not been touched by anyone, so the next sync
 * may update it. A field whose value differs — or that was emptied — was
 * edited in WordPress: the sync keeps it, the page shows it, and it is listed
 * on the yacht as "edited in WordPress". The only way back to Yachtfolio is
 * ticking the field there ("Use the Yachtfolio value").
 *
 * Detection compares values instead of watching the editor because the
 * classic editor posts every field on every save; "the admin opened the
 * yacht" says nothing about which field was changed. Values are normalised
 * first (entities, whitespace, "37.00" vs "37", repeater keys), since
 * JetEngine re-saves an untouched "July & August" as "July &amp; August".
 *
 * Hand-entered yachts (origin manual) keep the 2026-08-30 mandate: a value the
 * feed never wrote is never adopted, so only fields the feed itself filled
 * become feed-owned.
 */
final class EditTracker
{
    /** JSON map: field => md5 of the normalised value Yachtfolio last offered. */
    public const SNAPSHOT_META = 'yf_feed_values';

    /** Comma list of fields kept because they were edited in WordPress. */
    public const HELD_META = 'yf_protected_fields';
    public const HELD_COUNT_META = 'yf_protected_count';

    /** Comma list of held fields for which Yachtfolio has since sent a different value. */
    public const FEED_CHANGED_META = 'yf_feed_changed_fields';

    public const POST_FIELDS = ['post_title', 'post_content'];

    /** True while plugin code writes a yacht, so its own writes are not read as edits. */
    private static bool $suspended = false;

    /** @var array<int,array<int,string>> post id => fields to hand back to Yachtfolio on this save */
    private static array $reverts = [];

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        // After the post AND all its meta are saved — JetEngine writes its
        // fields on save_post, which runs after save_post_yacht.
        add_action('wp_after_insert_post', [$this, 'on_saved'], 20, 2);
    }

    public static function suspend(bool $on): void
    {
        self::$suspended = $on;
    }

    /** @param array<int,string> $fields */
    public static function request_revert(int $postId, array $fields): void
    {
        self::$reverts[$postId] = array_values(array_unique(array_merge(self::$reverts[$postId] ?? [], $fields)));
    }

    /* ------------------------------------------------------------------ *
     * Values
     * ------------------------------------------------------------------ */

    public static function current(int $postId, string $field): mixed
    {
        if (in_array($field, self::POST_FIELDS, true)) {
            return (string) get_post_field($field, $postId, 'raw');
        }
        return get_post_meta($postId, $field, true);
    }

    public static function normalise(string $field, mixed $value): string
    {
        if (is_bool($value)) {
            return MetaFormat::switcher($value);
        }
        if (is_string($value) && is_serialized($value)) {
            $value = maybe_unserialize($value);
        }
        if (is_array($value)) {
            $rows = self::normalise_rows($value);
            return $rows === [] ? '' : (string) wp_json_encode($rows);
        }

        $text = (string) $value;
        if ($field === 'post_content') {
            // TinyMCE rewrites markup on save; the words are what an editor changes.
            $text = wp_strip_all_tags($text);
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // All whitespace goes, not just runs of it: JetEngine's text fields drop
        // line breaks on save ("Baudouin\n2 x 50" comes back "Baudouin2 x 50"),
        // which would read as an edit on every untouched save.
        $text = (string) preg_replace('/[\s\p{Z}]+/u', '', $text);
        if ($text !== '' && is_numeric($text)) {
            $text = (string) (0 + $text); // "37.00" and "37" are the same length
        }
        return $text;
    }

    public static function fingerprint(string $field, mixed $value): string
    {
        return md5(self::normalise($field, $value));
    }

    public static function is_blank(string $field, mixed $value): bool
    {
        return Ownership::is_blank($field, self::normalise($field, $value));
    }

    /**
     * Repeater rows compared by content: item-N keys become positions, empty
     * cells and empty rows are dropped, sub-keys are sorted.
     *
     * @param array<int|string,mixed> $rows
     * @return array<int|string,mixed>
     */
    private static function normalise_rows(array $rows): array
    {
        $list = [];
        $assoc = [];
        foreach ($rows as $key => $value) {
            $norm = is_array($value) ? self::normalise_rows($value) : self::normalise('', $value);
            if ($norm === [] || $norm === '') {
                continue;
            }
            if (is_int($key) || preg_match('/^item-\d+$/', (string) $key) === 1) {
                $list[] = $norm;
            } else {
                $assoc[(string) $key] = $norm;
            }
        }
        ksort($assoc);
        if ($assoc === []) {
            return $list;
        }
        return $list === [] ? $assoc : ['rows' => $list, 'fields' => $assoc];
    }

    /* ------------------------------------------------------------------ *
     * Stored state
     * ------------------------------------------------------------------ */

    /** @return array<string,string> */
    public static function snapshot(int $postId): array
    {
        $raw = (string) get_post_meta($postId, self::SNAPSHOT_META, true);
        $decoded = $raw === '' ? null : json_decode($raw, true);
        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    /** @param array<string,string> $snapshot */
    public static function save_snapshot(int $postId, array $snapshot): void
    {
        ksort($snapshot);
        update_post_meta($postId, self::SNAPSHOT_META, wp_slash((string) wp_json_encode($snapshot)));
    }

    /** @return array<int,string> */
    public static function held(int $postId): array
    {
        return self::read_list($postId, self::HELD_META);
    }

    /** @return array<int,string> */
    public static function feed_changed(int $postId): array
    {
        return self::read_list($postId, self::FEED_CHANGED_META);
    }

    /**
     * @param array<int,string> $held
     * @param array<int,string> $feedChanged
     */
    public static function store_state(int $postId, array $held, array $feedChanged): void
    {
        $held = array_values(array_unique($held));
        sort($held);
        $feedChanged = array_values(array_intersect(array_unique($feedChanged), $held));
        update_post_meta($postId, self::HELD_META, implode(',', $held));
        update_post_meta($postId, self::HELD_COUNT_META, count($held));
        update_post_meta($postId, self::FEED_CHANGED_META, implode(',', $feedChanged));
    }

    /** @return array<int,string> */
    private static function read_list(int $postId, string $key): array
    {
        $raw = (string) get_post_meta($postId, $key, true);
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $f): bool => $f !== ''));
    }

    /* ------------------------------------------------------------------ *
     * Saving a yacht in WordPress
     * ------------------------------------------------------------------ */

    /**
     * Re-reads the yacht after any save, so the page switches the moment an
     * admin edits (or empties) a field, not at the next sync. Only fields the
     * admin explicitly hands back are refilled from Yachtfolio.
     */
    public function on_saved(int $postId, \WP_Post $post): void
    {
        if (self::$suspended || $post->post_type !== 'yacht' || wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        $snapshot = self::snapshot($postId);
        $previouslyHeld = self::held($postId);
        $reverts = self::$reverts[$postId] ?? [];
        unset(self::$reverts[$postId]);

        if ($snapshot === [] && $previouslyHeld === [] && $reverts === []) {
            return; // nothing from Yachtfolio on this yacht
        }

        $held = [];
        foreach (array_unique(array_merge(array_keys($snapshot), $previouslyHeld, $reverts)) as $field) {
            if (in_array($field, $reverts, true)) {
                continue;
            }
            $value = self::current($postId, $field);
            if (isset($snapshot[$field])
                ? self::fingerprint($field, $value) !== $snapshot[$field]
                // No fingerprint: a hand-entered value the feed never wrote.
                : in_array($field, $previouslyHeld, true)) {
                // Changed — or emptied — in WordPress: what the admin left is what shows.
                $held[] = $field;
            }
        }

        if ($reverts !== []) {
            $held = array_merge($held, $this->refill($postId, $reverts));
        }

        self::store_state($postId, $held, self::feed_changed($postId));
        Writer::refresh_rates_flag($postId);

        $yfId = (int) get_post_meta($postId, 'yf_id', true);
        if ($yfId > 0) {
            $this->plugin->map()->update($yfId, ['data_score' => DataScore::of($postId)]);
        }
    }

    /**
     * Writes the Yachtfolio value into the fields the admin handed back, from
     * the payload stored at the last sync — no API call.
     *
     * @param array<int,string> $fields
     * @return array<int,string> fields that could not be handed back and still hold a value
     */
    private function refill(int $postId, array $fields): array
    {
        $map = $this->plugin->orchestrator()->remap($postId);
        $snapshot = self::snapshot($postId);
        $stillHeld = [];

        self::$suspended = true;
        try {
            foreach ($fields as $field) {
                $offered = $map === null
                    ? null
                    : (in_array($field, self::POST_FIELDS, true) ? ($map->post[$field] ?? null) : ($map->meta[$field] ?? null));

                if ($map === null || in_array($field, self::POST_FIELDS, true) && ($offered === null || self::is_blank($field, $offered))) {
                    // No stored payload, or no title/description to give back:
                    // keep what is there rather than wiping it.
                    $stillHeld[] = $field;
                    continue;
                }
                if ($offered === null || self::is_blank($field, $offered)) {
                    // Yachtfolio has nothing for this field, so its value is "empty".
                    delete_post_meta($postId, $field);
                    $snapshot[$field] = md5('');
                    continue;
                }

                if (in_array($field, self::POST_FIELDS, true)) {
                    wp_update_post(['ID' => $postId, $field => (string) $offered]);
                    $stored = (string) $offered;
                } else {
                    [, $stored] = MetaFormat::for_value($offered);
                    update_post_meta($postId, $field, $stored);
                }
                $snapshot[$field] = self::fingerprint($field, $stored);
            }
        } finally {
            self::$suspended = false;
        }

        self::save_snapshot($postId, $snapshot);
        return $stillHeld;
    }

    /* ------------------------------------------------------------------ *
     * One-off baseline for yachts synced before this tracker existed
     * ------------------------------------------------------------------ */

    /**
     * Builds the fingerprints of a feed yacht synced before this tracker
     * existed. Never writes a field value.
     *
     * A yacht nobody has saved since its last sync still holds exactly what
     * that sync wrote, so its current values ARE the fingerprints. Re-mapping
     * the stored payload instead is wrong for those: the mapper has changed
     * since, and the season boxes depend on the date — measured on 60
     * untouched yachts, 45 would have been reported as edited.
     *
     * A yacht a person saved after its last sync is compared field by field
     * with what that sync wrote (the stored payload mapped as of the sync
     * date). Any difference is kept as an edit: a kept value is listed and can
     * be handed back, an overwritten one is gone.
     *
     * @return array{status:string,held:array<int,string>}
     */
    public function baseline(int $postId, bool $dryRun = false): array
    {
        if (Ownership::origin_of($postId) !== Ownership::ORIGIN_FEED) {
            return ['status' => 'manual', 'held' => self::held($postId)];
        }
        if (self::snapshot($postId) !== []) {
            return ['status' => 'exists', 'held' => self::held($postId)];
        }

        $syncedAt = (int) strtotime((string) get_post_meta($postId, 'yf_synced_at', true));
        $modified = (int) strtotime(get_post_field('post_modified_gmt', $postId) . ' UTC');
        $savedByPerson = (int) get_post_meta($postId, '_edit_last', true) > 0;
        $editedSince = $savedByPerson && $syncedAt > 0 && $modified > $syncedAt + 120;

        $map = $this->plugin->orchestrator()->remap($postId, $syncedAt > 0 ? $syncedAt : null);
        if ($map === null) {
            return ['status' => 'no_payload', 'held' => []];
        }

        $mapped = Ownership::api_owned_amenity_keys();
        [$allowed] = Ownership::filter($map->meta, $mapped);
        $offered = [];
        foreach (self::POST_FIELDS as $field) {
            if (array_key_exists($field, $map->post)) {
                $offered[$field] = $map->post[$field];
            }
        }
        foreach ($allowed as $key => $value) {
            if (Ownership::owner_of($key, $mapped) === 'api') {
                [, $offered[$key]] = MetaFormat::for_value($value);
            }
        }

        $snapshot = [];
        $held = [];
        foreach ($offered as $field => $value) {
            $current = self::current($postId, $field);
            if (!$editedSince || self::is_blank($field, $value)) {
                // Untouched since the sync wrote it (a blank feed value never
                // erases, so a value next to one also came from an earlier sync).
                $snapshot[$field] = self::fingerprint($field, $current);
                continue;
            }
            $snapshot[$field] = self::fingerprint($field, $value);
            if (!self::is_blank($field, $current) && self::fingerprint($field, $current) !== $snapshot[$field]) {
                $held[] = $field;
            }
        }

        if (!$dryRun) {
            self::save_snapshot($postId, $snapshot);
            self::store_state($postId, $held, []);
            Writer::refresh_rates_flag($postId);
        }
        return ['status' => $editedSince ? 'compared' : 'trusted', 'held' => $held];
    }
}
