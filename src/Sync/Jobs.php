<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Sync;

use Otium\Yachtfolio\Plugin;
use Otium\Yachtfolio\Write\Ownership;

/**
 * Action Scheduler wiring.
 *
 * Hooks:
 *   oy_yf_index    (array $opts)
 *   oy_yf_sync     (int $yfId, string $runId, array $opts)
 *   oy_yf_media    (int $yfId, string $runId)
 *   oy_yf_wave     (array $yfIds, string $runId, array $opts)   next batch
 *   oy_yf_finalize (string $runId)
 */
final class Jobs
{
    public const GROUP = 'oy_yf';

    public const HOOK_INDEX    = 'oy_yf_index';
    public const HOOK_SYNC     = 'oy_yf_sync';
    public const HOOK_MEDIA    = 'oy_yf_media';
    public const HOOK_WAVE     = 'oy_yf_wave';
    public const HOOK_FINALIZE = 'oy_yf_finalize';
    public const HOOK_CRON     = 'oy_yf_scheduled_run';

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action(self::HOOK_INDEX, [$this, 'handle_index'], 10, 1);
        add_action(self::HOOK_SYNC, [$this, 'handle_sync'], 10, 3);
        add_action(self::HOOK_MEDIA, [$this, 'handle_media'], 10, 2);
        add_action(self::HOOK_WAVE, [$this, 'handle_wave'], 10, 3);
        add_action(self::HOOK_FINALIZE, [$this, 'handle_finalize'], 10, 1);
        add_action(self::HOOK_CRON, [$this, 'handle_cron'], 10, 0);

        /**
         * Media follows curation.
         *
         * `yf_visible` is the admin's "this yacht belongs on the site" flag, and
         * the orchestrator already refuses to fetch media without it — sensibly,
         * since a full gallery runs to ~100 files per yacht. But nothing
         * connected the two: an admin ticked the box and nothing happened until
         * somebody happened to run a sync. On this site that meant **zero** feed
         * images ever reached a page.
         *
         * Hooked on the meta write rather than in the three places that set the
         * flag (metabox save, row toggle, bulk action), so no current or future
         * write path can miss it.
         */
        add_action('added_post_meta', [$this, 'on_visibility_meta'], 10, 4);
        add_action('updated_post_meta', [$this, 'on_visibility_meta'], 10, 4);
        add_action('init', [$this, 'sync_schedule'], 30);
    }

    /**
     * Queues a media fetch the moment a yacht is curated.
     *
     * @param int|string $metaId
     * @param int|string $postId
     * @param mixed      $value
     */
    public function on_visibility_meta(mixed $metaId, mixed $postId, string $metaKey, mixed $value): void
    {
        if ($metaKey !== 'yf_visible' || (string) $value !== 'true') {
            return;
        }

        $postId = (int) $postId;
        if ($postId <= 0 || get_post_type($postId) !== 'yacht') {
            return;
        }
        if (!$this->plugin->settings()->bool('import_media', true)) {
            return;
        }

        $yfId = (int) get_post_meta($postId, 'yf_id', true);
        if ($yfId <= 0) {
            return; // not linked to the feed, so there is nothing to fetch
        }

        // Already carries feed media: curation was toggled, not newly granted.
        $buckets = array_map('strtoupper', (array) $this->plugin->settings()->get('media_galleries', ['FULL', 'LAYOUT']));
        if ($this->plugin->mediaLedger()->attachments_for($yfId, $buckets) !== []) {
            return;
        }

        /**
         * A hand-built yacht with a gallery gets nothing downloaded.
         *
         * MediaImporter::sync_gallery_meta() would refuse to publish the files
         * anyway — that is the protection mandate — so fetching them means ~100
         * API calls and tens of megabytes that can never appear on the page.
         * ACAPELLA already has 45 curated images; the feed offers 110 it is not
         * allowed to show.
         *
         * Same spirit as fill_empty_only: the feed fills gaps, it does not
         * stockpile. An empty gallery on a manual yacht IS a gap, so that case
         * still fetches.
         */
        $mode = (string) $this->plugin->settings()->get('write_mode', 'fill_empty_only');
        if (Ownership::origin_of($postId) === Ownership::ORIGIN_MANUAL && $mode !== 'authoritative') {
            $gallery = trim((string) get_post_meta($postId, 'gallery', true));
            if ($gallery !== '') {
                $this->plugin->logger()->info('media', 'skipped: hand-built gallery already in place', [
                    'post_id' => $postId,
                    'images'  => substr_count($gallery, ',') + 1,
                ], $yfId);
                return;
            }
        }

        $this->enqueue_media($yfId);
        $this->plugin->logger()->info('media', 'queued: yacht marked visible', ['post_id' => $postId], $yfId);
    }

    /* ---------------- handlers ---------------- */

    /** @param array<string,mixed> $opts */
    public function handle_index(array $opts = []): void
    {
        $this->plugin->orchestrator()->run_index(is_array($opts) ? $opts : []);
    }

    /** @param array<string,mixed> $opts */
    public function handle_sync(int $yfId, string $runId = '', array $opts = []): void
    {
        $opts = is_array($opts) ? $opts : [];
        $opts['run_id'] = $runId;
        $this->plugin->orchestrator()->sync_yacht($yfId, $opts);
    }

    public function handle_media(int $yfId, string $runId = ''): void
    {
        $this->plugin->orchestrator()->sync_media($yfId, $runId);
    }

    /**
     * @param array<int,int> $yfIds
     * @param array<string,mixed> $opts
     */
    public function handle_wave(array $yfIds, string $runId = '', array $opts = []): void
    {
        $batch = $this->plugin->settings()->int('batch_size', 10);
        $slice = array_slice($yfIds, 0, $batch);
        $rest  = array_slice($yfIds, $batch);

        foreach ($slice as $yfId) {
            $this->enqueue_yacht((int) $yfId, $runId, is_array($opts) ? $opts : []);
        }

        if ($rest !== []) {
            // Chain the next wave so a 433-yacht pass never buries the queue.
            $this->schedule_single(self::HOOK_WAVE, [$rest, $runId, $opts], 60);
        } else {
            $this->schedule_single(self::HOOK_FINALIZE, [$runId], 120);
        }
    }

    public function handle_finalize(string $runId): void
    {
        $this->plugin->orchestrator()->finalize($runId);
    }

    public function handle_cron(): void
    {
        $this->plugin->orchestrator()->run_index(['trigger' => 'cron']);
    }

    /* ---------------- enqueueing ---------------- */

    /** @param array<string,mixed> $opts */
    public function enqueue_index(array $opts = []): int
    {
        return $this->enqueue(self::HOOK_INDEX, [$opts]);
    }

    /**
     * @param array<int,int> $yfIds
     * @param array<string,mixed> $opts
     * @return int number queued in the first wave
     */
    public function enqueue_wave(array $yfIds, string $runId, array $opts = []): int
    {
        $batch = $this->plugin->settings()->int('batch_size', 10);
        $first = array_slice($yfIds, 0, $batch);
        $rest  = array_slice($yfIds, $batch);

        foreach ($first as $yfId) {
            $this->enqueue_yacht((int) $yfId, $runId, $opts);
        }

        if ($rest !== []) {
            $this->schedule_single(self::HOOK_WAVE, [$rest, $runId, $opts], 60);
        } else {
            $this->schedule_single(self::HOOK_FINALIZE, [$runId], 120);
        }

        return count($first);
    }

    /** @param array<string,mixed> $opts */
    public function enqueue_yacht(int $yfId, string $runId, array $opts = []): int
    {
        return $this->enqueue(self::HOOK_SYNC, [$yfId, $runId, $opts]);
    }

    public function enqueue_media(int $yfId, string $runId = ''): int
    {
        return $this->enqueue(self::HOOK_MEDIA, [$yfId, $runId]);
    }

    public function enqueue_finalize(string $runId, int $afterSeconds = 60): int
    {
        return $this->schedule_single(self::HOOK_FINALIZE, [$runId], $afterSeconds);
    }

    /* ---------------- schedule ---------------- */

    public function sync_schedule(): void
    {
        $schedule = (string) $this->plugin->settings()->get('schedule', 'off');
        $interval = $this->interval($schedule);

        if (!function_exists('as_has_scheduled_action')) {
            return;
        }

        $scheduled = as_has_scheduled_action(self::HOOK_CRON, [], self::GROUP);

        if ($interval === 0) {
            if ($scheduled) {
                $this->unschedule_recurring();
            }
            return;
        }

        if (!$scheduled) {
            as_schedule_recurring_action(time() + $interval, $interval, self::HOOK_CRON, [], self::GROUP);
        }
    }

    public function unschedule_recurring(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK_CRON, [], self::GROUP);
        }
    }

    public function next_scheduled(): ?int
    {
        if (!function_exists('as_next_scheduled_action')) {
            return null;
        }
        $next = as_next_scheduled_action(self::HOOK_CRON, [], self::GROUP);
        return is_int($next) && $next > 0 ? $next : null;
    }

    /** @return array<string,int> */
    public function queue_depth(): array
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return [];
        }
        $out = [];
        foreach ([self::HOOK_SYNC, self::HOOK_MEDIA, self::HOOK_WAVE] as $hook) {
            $actions = as_get_scheduled_actions([
                'hook'     => $hook,
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
                'group'    => self::GROUP,
                'per_page' => 500,
            ], 'ids');
            $out[$hook] = is_array($actions) ? count($actions) : 0;
        }
        return $out;
    }

    /* ---------------- internals ---------------- */

    /** @param array<int,mixed> $args */
    private function enqueue(string $hook, array $args): int
    {
        if (!function_exists('as_enqueue_async_action')) {
            // No queue available (bare CLI): run inline so nothing is lost.
            do_action_ref_array($hook, $args);
            return 0;
        }

        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action($hook, $args, self::GROUP)) {
            return 0; // never double-queue identical work
        }

        return (int) as_enqueue_async_action($hook, $args, self::GROUP);
    }

    /** @param array<int,mixed> $args */
    private function schedule_single(string $hook, array $args, int $afterSeconds): int
    {
        if (!function_exists('as_schedule_single_action')) {
            do_action_ref_array($hook, $args);
            return 0;
        }
        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action($hook, $args, self::GROUP)) {
            return 0;
        }
        return (int) as_schedule_single_action(time() + max(1, $afterSeconds), $hook, $args, self::GROUP);
    }

    private function interval(string $schedule): int
    {
        return match ($schedule) {
            'hourly'     => HOUR_IN_SECONDS,
            'six_hours'  => 6 * HOUR_IN_SECONDS,
            'daily'      => DAY_IN_SECONDS,
            default      => 0,
        };
    }
}
