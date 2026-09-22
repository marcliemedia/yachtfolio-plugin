<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Sync;

use Otium\Yachtfolio\Api\ApiError;
use Otium\Yachtfolio\Domain\Hasher;
use Otium\Yachtfolio\Plugin;
use Otium\Yachtfolio\Support\Secrets;

/**
 * Drives a sync pass.
 *
 * Shape of a pass: one index call for all 433 yachts (cheap), one bulk call for
 * the 5 owned yachts, then one brochure call per *selected* yacht. Detail and
 * media are curated on purpose — importing every yacht's gallery would be
 * ~19 000 downloads.
 */
final class Orchestrator
{
    private const BULK_TRANSIENT = 'oy_yf_bulk_';

    public function __construct(private Plugin $plugin)
    {
    }

    /**
     * @param array{dry_run?:bool,trigger?:string,scope?:string,limit?:int,force?:bool,enqueue?:bool} $opts
     * @return array<string,mixed>
     */
    public function run_index(array $opts = []): array
    {
        $dryRun  = (bool) ($opts['dry_run'] ?? false);
        $trigger = (string) ($opts['trigger'] ?? 'manual');
        $scope   = (string) ($opts['scope'] ?? $this->plugin->settings()->get('detail_scope', 'selected'));
        $limit   = (int) ($opts['limit'] ?? 0);
        $force   = (bool) ($opts['force'] ?? false);
        $enqueue = (bool) ($opts['enqueue'] ?? true);

        $lock = $this->plugin->lock();
        if (!$lock->acquire('index', 1800)) {
            return ['ok' => false, 'error' => 'another index pass is already running'];
        }

        $runs = $this->plugin->runs();
        $runId = $runs->start($this->plugin->settings()->mode(), $dryRun, $trigger);
        $log = $this->plugin->logger();
        $log->set_run($runId);

        $summary = [
            'ok'         => true,
            'run_id'     => $runId,
            'dry_run'    => $dryRun,
            'created'    => 0,
            'updated'    => 0,
            'skipped'    => 0,
            'errors'     => 0,
            'attention'  => 0,
            'candidates' => [],
            'api_calls'  => 0,
            'authorisation_lost'     => [],
            'authorisation_regained' => [],
            'detail_unavailable'     => [],
            'detail_regained'        => [],
        ];

        try {
            $reference = $this->plugin->reference();
            if ($force || $reference->is_stale()) {
                $counts = $reference->refresh($force);
                $log->info('run', 'reference cache refreshed', $counts);
            }

            $rows = $this->plugin->client()->list();
            $map = $this->plugin->map();
            $indexResult = $map->upsert_index($rows);
            $summary['index'] = $indexResult;
            $log->info('list', 'index synced', $indexResult + ['rows' => count($rows)]);

            $seen = array_map(static fn(array $r): int => (int) $r['id'], $rows);
            $stale = $map->mark_missing_stale($seen);
            if ($stale > 0) {
                $this->demote_stale();
                $log->info('list', 'yachts missing from the feed marked stale', ['count' => $stale]);
            }
            $summary['stale'] = $stale;

            // Owned yachts: the only source of equipment ids, the full rate
            // history, licences and special requests.
            $bulk = [];
            try {
                $bulk = $this->plugin->client()->bulk_yachts();

                if ($bulk !== []) {
                    // Reconcile the owned flag against reality. Before this the
                    // flag was only ever set to 1 and never cleared, so a
                    // withdrawn authorisation stayed invisible for ever.
                    $ownedIds = array_map('intval', array_keys($bulk));

                    $map->reconcile_owned($ownedIds);

                    $sweep = $map->sweep_detail_availability($ownedIds);
                    $summary['detail_unavailable'] = $sweep['flagged'];
                    $summary['detail_regained'] = $sweep['cleared'];

                    foreach ($sweep['flagged'] as $lostId) {
                        $lostRow = $map->get($lostId);
                        $log->warn('bulk', 'no structured detail record; keeping the data already written', [
                            'yacht_name' => (string) ($lostRow['yacht_name'] ?? ''),
                            'post_id'    => (int) ($lostRow['post_id'] ?? 0),
                        ], $lostId);
                    }
                    foreach ($sweep['cleared'] as $backId) {
                        $log->info('bulk', 'structured detail record available again', [], $backId);
                    }

                    foreach ($ownedIds as $yfId) {
                        $map->clear_authorisation_lost($yfId);
                    }
                } else {
                    // One empty bulk response is far more likely to be a
                    // transient fault than the whole fleet being revoked at
                    // once, so the flags are left alone on purpose.
                    $log->warn('bulk', 'bulk detail returned zero yachts; owned flags left untouched');
                }

                set_transient(self::BULK_TRANSIENT . $runId, $bulk, HOUR_IN_SECONDS);
                $log->info('bulk', 'bulk detail cached for this run', ['yachts' => count($bulk)]);
            } catch (ApiError $e) {
                $log->warn('bulk', 'bulk detail unavailable: ' . $e->getMessage());
            }
            $summary['owned'] = count($bulk);
            $summary['authorisation_lost_total'] = $map->count_authorisation_lost();

            $candidates = [];
            foreach ($map->due_for_detail($scope === 'all', 0) as $row) {
                if (!$force && !$this->needs_detail($row)) {
                    $summary['skipped']++;
                    continue;
                }
                $candidates[] = (int) $row['yf_id'];
                if ($limit > 0 && count($candidates) >= $limit) {
                    break;
                }
            }
            $summary['candidates'] = $candidates;

            $summary['api_calls'] = $this->plugin->client()->calls();
            $runs->bump($runId, ['skipped' => $summary['skipped'], 'api_calls' => $summary['api_calls']]);

            if ($enqueue && $candidates !== []) {
                $queued = $this->plugin->jobs()->enqueue_wave($candidates, $runId, [
                    'dry_run' => $dryRun,
                    'force'   => $force,
                ]);
                $summary['queued'] = $queued;
                $log->info('run', 'detail jobs queued', ['queued' => $queued, 'total' => count($candidates)]);
            } elseif (!$enqueue) {
                $log->info('run', 'index finished without enqueuing', ['candidates' => count($candidates)]);
            }

            if ($candidates === []) {
                $this->finalize($runId);
            }
        } catch (ApiError $e) {
            $summary['ok'] = false;
            $summary['errors']++;
            $summary['error'] = $e->kind . ': ' . Secrets::scrub($e->getMessage());
            $log->error('run', 'index pass failed: ' . $e->getMessage(), ['kind' => $e->kind]);
            $runs->bump($runId, ['errors' => 1]);
            $runs->finish($runId, $summary);
        } catch (\Throwable $e) {
            $summary['ok'] = false;
            $summary['errors']++;
            $summary['error'] = (string) Secrets::scrub($e->getMessage());
            $log->error('run', 'index pass crashed: ' . $e->getMessage(), []);
            $runs->bump($runId, ['errors' => 1]);
            $runs->finish($runId, $summary);
        } finally {
            $lock->release('index');
        }

        return $summary;
    }

    /**
     * @param array{dry_run?:bool,force?:bool,run_id?:string,with_media?:bool,trigger?:string} $opts
     * @return array<string,mixed>
     */
    public function sync_yacht(int $yfId, array $opts = []): array
    {
        $dryRun    = (bool) ($opts['dry_run'] ?? false);
        $force     = (bool) ($opts['force'] ?? false);
        $withMedia = (bool) ($opts['with_media'] ?? true);
        $runId     = (string) ($opts['run_id'] ?? '');

        $runs = $this->plugin->runs();
        $adhoc = false;
        if ($runId === '') {
            $runId = $runs->start($this->plugin->settings()->mode(), $dryRun, (string) ($opts['trigger'] ?? 'manual'));
            $adhoc = true;
        }

        $log = $this->plugin->logger();
        $log->set_run($runId);

        $map = $this->plugin->map();
        $row = $map->get($yfId);

        $result = [
            'status'    => 'error',
            'run_id'    => $runId,
            'post_id'   => null,
            'message'   => '',
            'diff'      => [],
            'attention' => [],
        ];

        if ($row === null) {
            $result['message'] = "yacht $yfId is not in the index; run the index pass first";
            if ($adhoc) {
                $runs->finish($runId, $result);
            }
            return $result;
        }

        $postId = $row['post_id'] !== null ? (int) $row['post_id'] : null;

        try {
            $settings = $this->plugin->settings();
            $client = $this->plugin->client();

            $brochure = [];
            $brochureDenied = false;
            if ($settings->bool('import_brochure', true)) {
                try {
                    $brochure = $client->brochure($yfId);
                } catch (ApiError $e) {
                    // A denied or empty brochure is not automatically a hard
                    // error: the yacht may simply have lost its authorisation.
                    // Defer the verdict to the classification below.
                    if ($e->retryable() || !in_array($e->kind, [ApiError::EMPTY_PAYLOAD, ApiError::PERMISSION], true)) {
                        throw $e;
                    }
                    $brochureDenied = true;
                    $log->warn('brochure', 'brochure unavailable: ' . $e->getMessage(), ['kind' => $e->kind], $yfId);
                }
            }

            $bulk = $this->bulk_record($runId, $yfId, (bool) $row['owned']);

            if ($brochure === [] && $bulk === null) {
                // Yachtfolio revokes a per-yacht authorisation silently: HTTP
                // 200, errors:[], zero rows. Distinguish that from a yacht that
                // never had access, and from one that left the feed entirely
                // (which is 'stale' and handled elsewhere).
                $everSynced = (string) $row['payload_hash'] !== ''
                    || (string) $row['status'] === YachtMapStore::STATUS_SYNCED;
                $stillListed = (string) $row['status'] !== YachtMapStore::STATUS_STALE;

                if ($everSynced && $stillListed) {
                    $firstTime = $map->flag_authorisation_lost(
                        $yfId,
                        'detail and brochure both returned nothing while the yacht is still listed'
                    );
                    $runs->bump($runId, ['attention' => 1]);

                    $context = [
                        'post_id'         => $postId,
                        'brochure_denied' => $brochureDenied,
                        'owned_flag'      => (int) $row['owned'],
                    ];
                    if ($firstTime) {
                        $log->warn('brochure', 'authorisation lost; existing content left untouched and the post was NOT unpublished', $context, $yfId);
                    } else {
                        $log->info('brochure', 'authorisation still missing; nothing changed', $context, $yfId);
                    }

                    $result['status']    = 'authorisation_lost';
                    $result['post_id']   = $postId;
                    $result['attention'] = [YachtMapStore::ATTENTION_AUTH_LOST];
                    $result['message']   = $firstTime
                        ? 'authorisation withdrawn by Yachtfolio; data kept, nothing unpublished'
                        : 'authorisation still withdrawn; data kept';

                    if ($adhoc) {
                        $runs->finish($runId, $result);
                    }
                    return $result;
                }

                throw new ApiError(ApiError::EMPTY_PAYLOAD, 'no brochure and no bulk record available');
            }

            $payload = $this->plugin->normalizer()->from(
                $yfId,
                (string) $row['yacht_name'],
                (string) $row['registry_port'],
                $brochure,
                $bulk,
                (string) $row['last_modified_remote']
            );

            $hash = Hasher::of($payload);
            if (!$force && !$dryRun && $hash === (string) $row['brochure_hash'] && $row['status'] === YachtMapStore::STATUS_SYNCED) {
                $map->update($yfId, ['last_seen_at' => current_time('mysql', true)]);
                $runs->bump($runId, ['skipped' => 1]);
                $log->info('brochure', 'unchanged, skipped', ['hash' => substr($hash, 0, 12)], $yfId);
                $result['status'] = 'skipped';
                $result['post_id'] = $postId;
                $result['message'] = 'content hash unchanged';
                if ($adhoc) {
                    $runs->finish($runId, $result);
                }
                return $result;
            }

            $mapResult = $this->plugin->mapper()->map($payload);
            $mapResult->report->persist($runId);

            $writer = $this->plugin->writer();

            if ($dryRun) {
                $diffs = $writer->plan($payload, $mapResult, $postId);
                $result['status'] = 'dry_run';
                $result['post_id'] = $postId;
                $result['diff'] = array_map(static fn($d): array => $d->as_row(), $diffs);
                $result['attention'] = $mapResult->flags;
                $changed = count(array_filter($diffs, static fn($d): bool => $d->changed));
                $result['message'] = sprintf('%d of %d fields would change', $changed, count($diffs));
                $log->info('map', 'dry run computed', ['changed' => $changed, 'total' => count($diffs)], $yfId);
                if ($adhoc) {
                    $runs->finish($runId, ['dry_run' => true, 'yf_id' => $yfId]);
                }
                return $result;
            }

            $created = $postId === null;
            $postId = $writer->apply($payload, $mapResult, $postId);

            $mediaTotal = 0;
            foreach ((array) $settings->get('media_galleries', ['FULL', 'LAYOUT']) as $bucket) {
                $mediaTotal += count($payload->gallery(strtoupper((string) $bucket)));
            }

            $map->update($yfId, [
                'post_id'              => $postId,
                'status'               => YachtMapStore::STATUS_SYNCED,
                'payload_hash'         => $hash,
                'brochure_hash'        => $hash,
                'last_modified_remote' => $payload->last_modified !== '' ? $payload->last_modified : (string) $row['last_modified_remote'],
                'media_total'          => $mediaTotal,
                'image_count'          => $this->plugin->mediaLedger()->count_for($yfId),
                'attention'            => $mapResult->flags_csv(),
                'last_error'           => null,
                'last_synced_at'       => current_time('mysql', true),
                'selected'             => 1,
                // Index of the post's presence markers so the admin list can
                // sort and filter on completeness in SQL.
                'data_score'           => \Otium\Yachtfolio\Write\DataScore::of($postId),
            ]);

            // A successful sync means the brochure scope is fine again.
            if ($map->clear_authorisation_lost($yfId)) {
                $log->info('write', 'authorisation regained; yacht syncing again', ['post_id' => $postId], $yfId);
            }

            // Independently of that, record whether the 89-field structured
            // record came with it. Checked as a STANDING state on every run
            // rather than as a 1->0 transition, so a missed run cannot hide it.
            $detailMissing = $bulk === null;
            if ($detailMissing) {
                if ($map->flag_detail_unavailable($yfId)) {
                    $log->warn('write', 'no structured detail record: rates, equipment and cruising areas are frozen at their last synced values', [
                        'post_id' => $postId,
                    ], $yfId);
                }
            } elseif ($map->clear_detail_unavailable($yfId)) {
                $log->info('write', 'structured detail record available again', ['post_id' => $postId], $yfId);
            }

            $runs->bump($runId, [
                $created ? 'created' : 'updated' => 1,
                'attention' => $mapResult->flags === [] ? 0 : 1,
            ]);


            $result['status'] = 'synced';
            $result['post_id'] = $postId;
            $result['attention'] = $mapResult->flags;
            if ($detailMissing) {
                // Appended AFTER the assignment above, which replaces the array.
                $result['attention'][] = YachtMapStore::ATTENTION_DETAIL_LOST;
            }
            $result['message'] = $created ? 'created as draft' : 'updated';

            // Surface what the protection gate held back, so "nothing changed"
            // is never confused with "the feed had nothing to say".
            $writeReport = $this->plugin->writer()->last_report();
            $protectedCount = (int) ($writeReport['meta_protected'] ?? 0);
            if ($protectedCount > 0) {
                $result['protected_fields'] = $writeReport['protected_fields'] ?? [];
                $result['message'] .= sprintf('; kept %d hand-entered field(s)', $protectedCount);
                $runs->bump($runId, ['attention' => 1]);
            }

            $visible = (string) get_post_meta($postId, 'yf_visible', true) === 'true';
            if ($withMedia && $settings->bool('import_media', true) && $visible) {
                $this->plugin->jobs()->enqueue_media($yfId, $runId);
                $result['message'] .= '; media queued';
            }
        } catch (ApiError $e) {
            $message = $e->kind . ': ' . Secrets::scrub($e->getMessage());
            if ($e->retryable()) {
                $log->warn('brochure', 'retryable failure: ' . $e->getMessage(), ['kind' => $e->kind], $yfId);
                $map->set_status($yfId, YachtMapStore::STATUS_PENDING, (string) $message);
                throw $e; // let Action Scheduler retry
            }
            $map->set_status($yfId, YachtMapStore::STATUS_ERROR, (string) $message);
            $runs->bump($runId, ['errors' => 1]);
            $log->error('brochure', 'sync failed: ' . $e->getMessage(), ['kind' => $e->kind], $yfId);
            $result['message'] = (string) $message;
        } catch (\Throwable $e) {
            $message = (string) Secrets::scrub($e->getMessage());
            $map->set_status($yfId, YachtMapStore::STATUS_ERROR, $message);
            $runs->bump($runId, ['errors' => 1]);
            $log->error('write', 'sync crashed: ' . $e->getMessage(), [], $yfId);
            $result['message'] = $message;
        }

        if ($adhoc) {
            $runs->finish($runId, ['yf_id' => $yfId, 'status' => $result['status']]);
        }

        return $result;
    }

    /**
     * Imports a yacht's media. Single-flight per yacht.
     *
     * Two paths reach this: the queued job created when a yacht is marked
     * visible, and an explicit sync. Running both at once imported every image
     * twice — the ledger's UNIQUE(id_file) cannot help, because both passes
     * check for the file before either has inserted its row. Measured on a live
     * test: ANNABEL II ended with 69 attachments for 35 ledger rows, 23.6 MB of
     * duplicates across three yachts.
     *
     * @return array<string,mixed>
     */
    public function sync_media(int $yfId, string $runId = ''): array
    {
        $map = $this->plugin->map();
        $row = $map->get($yfId);
        if ($row === null || empty($row['post_id'])) {
            return ['ok' => false, 'message' => "yacht $yfId has no linked post"];
        }

        $lock = $this->plugin->lock();
        $lockName = 'media_' . $yfId;
        if (!$lock->acquire($lockName, 900)) {
            $this->plugin->logger()->info('media', 'another media pass is already running for this yacht; skipped', [], $yfId);
            return ['ok' => true, 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'message' => 'already running'];
        }

        $log = $this->plugin->logger();
        if ($runId !== '') {
            $log->set_run($runId);
        }

        $postId = (int) $row['post_id'];
        $raw = \Otium\Yachtfolio\Write\Writer::read_raw($postId);
        $brochure = [];
        $bulk = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $brochure = is_array($decoded['brochure'] ?? null) ? $decoded['brochure'] : [];
                $bulk = is_array($decoded['bulk'] ?? null) ? $decoded['bulk'] : null;
            }
        }

        try {
            if ($brochure === []) {
                $brochure = $this->plugin->client()->brochure($yfId);
            }

            $payload = $this->plugin->normalizer()->from(
                $yfId,
                (string) $row['yacht_name'],
                (string) $row['registry_port'],
                $brochure,
                $bulk,
                (string) $row['last_modified_remote']
            );

            $result = $this->plugin->media()->import_yacht($payload, $postId);

            $map->update($yfId, [
                'image_count' => $this->plugin->mediaLedger()->count_for($yfId),
                'media_total' => $result['imported'] + $result['skipped'] + $result['failed'],
                // The gallery is one of the scored sections, so importing media
                // can move the score on its own.
                'data_score'  => \Otium\Yachtfolio\Write\DataScore::of($postId),
            ]);

            if ($runId !== '') {
                $this->plugin->runs()->bump($runId, ['media_calls' => $result['imported']]);
            }

            return ['ok' => true] + $result;
        } catch (ApiError $e) {
            $log->warn('media', 'media pass interrupted: ' . $e->getMessage(), ['kind' => $e->kind], $yfId);
            if ($e->retryable()) {
                // Action Scheduler will retry, so the lock must not outlive
                // this attempt.
                $lock->release($lockName);
                throw $e;
            }
            return ['ok' => false, 'message' => (string) Secrets::scrub($e->getMessage())];
        } finally {
            $lock->release($lockName);
        }
    }

    /** @return array<string,mixed> */
    public function finalize(string $runId): array
    {
        $log = $this->plugin->logger();
        $log->set_run($runId);

        $map = $this->plugin->map();
        $counts = $map->status_counts();
        $attention = $map->count(['attention' => true]);

        $purged = $log->purge($this->plugin->settings()->int('log_retention_days', 30));
        delete_transient(self::BULK_TRANSIENT . $runId);

        $summary = [
            'statuses'   => $counts,
            'attention'  => $attention,
            'logs_purged' => $purged,
            'budget_used' => $this->plugin->budget()->used(),
        ];

        $this->plugin->runs()->finish($runId, $summary);
        $log->info('run', 'run finalised', $summary);

        $this->purge_caches();

        return $summary;
    }

    /** @return array<int,array<string,string>> */
    public function dry_run_yacht(int $yfId): array
    {
        $result = $this->sync_yacht($yfId, ['dry_run' => true, 'trigger' => 'manual']);
        return is_array($result['diff']) ? $result['diff'] : [];
    }

    /* ------------------------------------------------------------------ */

    /**
     * Cheap delta path: the live feed carries a real per-yacht last_modified
     * (433 distinct values), so an unchanged timestamp means no brochure call.
     *
     * @param array<string,mixed> $row
     */
    private function needs_detail(array $row): bool
    {
        if ((string) $row['status'] !== YachtMapStore::STATUS_SYNCED) {
            return true;
        }
        if ((string) $row['payload_hash'] === '') {
            return true;
        }
        $postId = (int) ($row['post_id'] ?? 0);
        if ($postId <= 0) {
            return true;
        }
        $synced = (string) get_post_meta($postId, 'yf_last_modified', true);
        return $synced === '' || $synced !== (string) $row['last_modified_remote'];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function bulk_record(string $runId, int $yfId, bool $owned): ?array
    {
        if (!$owned) {
            // For anything the key does not own the single-yacht endpoint
            // answers {"data":[],"errors":[]} — a silent empty. Never call it.
            return null;
        }

        $cached = get_transient(self::BULK_TRANSIENT . $runId);
        if (is_array($cached) && isset($cached[$yfId]) && is_array($cached[$yfId])) {
            return $cached[$yfId];
        }

        try {
            return $this->plugin->client()->single_yacht($yfId);
        } catch (ApiError $e) {
            $this->plugin->logger()->warn('bulk', 'owned single fetch failed: ' . $e->getMessage(), ['kind' => $e->kind], $yfId);
            return null;
        }
    }

    /** Yachts that left the feed go back to draft; nothing is ever deleted. */
    private function demote_stale(): void
    {
        foreach ($this->plugin->map()->all(['status' => YachtMapStore::STATUS_STALE, 'limit' => 500]) as $row) {
            $postId = (int) ($row['post_id'] ?? 0);
            if ($postId <= 0) {
                continue;
            }
            $post = get_post($postId);
            if ($post && $post->post_status === 'publish') {
                wp_update_post(['ID' => $postId, 'post_status' => 'draft']);
                update_post_meta($postId, 'yf_sync_status', YachtMapStore::STATUS_STALE);
                $this->plugin->logger()->warn('write', 'yacht left the feed, post moved back to draft', ['post_id' => $postId], (int) $row['yf_id']);
            }
        }
    }

    private function purge_caches(): void
    {
        wp_cache_flush();
        if (has_action('sg_cachepress_purge_cache')) {
            do_action('sg_cachepress_purge_cache');
        }
        if (function_exists('sg_cachepress_purge_everything')) {
            sg_cachepress_purge_everything();
        }
    }
}
