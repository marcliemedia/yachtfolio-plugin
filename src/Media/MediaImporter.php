<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Media;

use Otium\Yachtfolio\Api\ApiError;
use Otium\Yachtfolio\Api\Client;
use Otium\Yachtfolio\Domain\YachtPayload;
use Otium\Yachtfolio\Support\Logger;
use Otium\Yachtfolio\Support\Settings;
use Otium\Yachtfolio\Write\MetaFormat;
use Otium\Yachtfolio\Write\Ownership;

/**
 * Downloads gallery files into the media library.
 *
 * Two verified facts drive the design:
 *  - the API serves WebP bytes under `.jpg` filenames with
 *    `content-type: image/jpeg`, so the real format is sniffed and the file is
 *    renamed before sideloading, otherwise WordPress refuses it;
 *  - EXTERIOR/INTERIOR/LIFESTYLE/PDF are 100% subsets of FULL while LAYOUT is
 *    not, so the default import set is FULL ∪ LAYOUT, deduped by id_file.
 */
final class MediaImporter
{
    public function __construct(
        private Client $client,
        private MediaLedger $ledger,
        private Settings $settings,
        private Logger $log
    ) {
    }

    /**
     * @param array{limit?:int,galleries?:array<int,string>} $opts
     * @return array{imported:int,skipped:int,failed:int,ids:array<int,int>}
     */
    public function import_yacht(YachtPayload $payload, int $postId, array $opts = []): array
    {
        $buckets = $opts['galleries'] ?? (array) $this->settings->get('media_galleries', ['FULL', 'LAYOUT']);
        $cap = (int) ($opts['limit'] ?? $this->settings->int('media_cap_per_yacht', 60));

        $queue = [];
        $seen = [];
        foreach ($buckets as $bucket) {
            $bucket = strtoupper((string) $bucket);
            foreach ($payload->gallery($bucket) as $file) {
                $idFile = (string) $file['id_file'];
                if (isset($seen[$idFile])) {
                    continue;
                }
                $seen[$idFile] = true;
                $queue[] = ['file' => $file, 'gallery' => $bucket];
            }
        }

        if ($cap > 0) {
            $queue = array_slice($queue, 0, $cap);
        }

        $result = ['imported' => 0, 'skipped' => 0, 'failed' => 0, 'ids' => []];

        foreach ($queue as $index => $item) {
            $idFile = (string) $item['file']['id_file'];
            $existing = $this->ledger->find($idFile);
            if ($existing !== null && $this->ledger->attachment_exists((int) $existing['attachment_id'])) {
                $result['skipped']++;
                $result['ids'][] = (int) $existing['attachment_id'];
                continue;
            }

            try {
                $attachmentId = $this->import_file($item['file'], $payload->yf_id, $item['gallery'], $postId, $payload->name, $index);
            } catch (ApiError $e) {
                if (in_array($e->kind, [ApiError::BUDGET, ApiError::RATELIMIT], true)) {
                    // Stop this yacht and let the queue reschedule the rest.
                    $this->log->warn('media', 'media import paused: ' . $e->getMessage(), [
                        'imported' => $result['imported'],
                        'remaining' => count($queue) - $index,
                    ], $payload->yf_id);
                    $this->sync_gallery_meta($postId, $payload->yf_id);
                    throw $e;
                }
                $result['failed']++;
                $this->log->warn('media', 'file failed: ' . $e->getMessage(), ['id_file' => $idFile], $payload->yf_id);
                continue;
            }

            if ($attachmentId > 0) {
                $result['imported']++;
                $result['ids'][] = $attachmentId;
            } else {
                $result['failed']++;
            }
        }

        if ($payload->sample_menu !== null && $this->settings->bool('import_sample_menu', false)) {
            try {
                $menuId = $this->import_file(
                    ['id_file' => $payload->sample_menu['id_file'], 'filename' => $payload->sample_menu['filename'], 'title' => 'Sample menu', 'id_order' => 0],
                    $payload->yf_id,
                    'MENU',
                    $postId,
                    $payload->name,
                    0
                );
                if ($menuId > 0) {
                    update_post_meta($postId, 'yf_sample_menu_pdf', $menuId);
                    $result['imported']++;
                }
            } catch (ApiError $e) {
                $result['failed']++;
                $this->log->warn('media', 'sample menu failed: ' . $e->getMessage(), [], $payload->yf_id);
            }
        }

        if ($this->settings->bool('import_crew_photos', true)) {
            $result = $this->import_crew_photos($payload, $postId, $result);
        }

        $this->sync_gallery_meta($postId, $payload->yf_id);

        $this->log->info('media', 'media import finished', $result + ['queued' => count($queue)], $payload->yf_id);

        return $result;
    }

    /**
     * @param array{id_file:string,filename:string,title:?string,id_order:?int} $file
     * @throws ApiError
     */
    public function import_file(array $file, int $yfId, string $gallery, ?int $postId = null, string $yachtName = '', int $index = 0): int
    {
        $idFile = (string) $file['id_file'];
        $filename = (string) $file['filename'];

        $existing = $this->ledger->find($idFile);
        if ($existing !== null && $this->ledger->attachment_exists((int) $existing['attachment_id'])) {
            return (int) $existing['attachment_id'];
        }

        $bytes = $this->client->media_bytes($filename);

        $realExt = FormatSniffer::sniff_bytes($bytes);
        if ($realExt === '' || !FormatSniffer::is_allowed($realExt)) {
            $this->log->warn('media', 'refused: unrecognised or disallowed file type', [
                'id_file'  => $idFile,
                'filename' => $filename,
                'sniffed'  => $realExt,
            ], $yfId);
            return 0;
        }

        /**
         * Re-encode to WebP here, not later.
         *
         * The site's WebP plugin never converts anything (see WebpEncoder for
         * the two structural reasons), so a JPEG written now stays a JPEG
         * forever. Doing it on the bytes we already hold means WordPress then
         * generates every thumbnail size from the WebP too, instead of
         * producing a JPEG set that also needs converting.
         */
        $downloadedBytes = strlen($bytes);
        if ($this->settings->bool('convert_to_webp', true) && WebpEncoder::convertible($realExt)) {
            $quality = $this->settings->int('webp_quality', WebpEncoder::DEFAULT_QUALITY);
            $webp = WebpEncoder::encode($bytes, $realExt, $quality);
            if ($webp !== null) {
                $this->log->debug('media', 'converted to webp', [
                    'id_file' => $idFile,
                    'from'    => $realExt,
                    'before'  => $downloadedBytes,
                    'after'   => strlen($webp),
                    'saved'   => (int) round(100 - (strlen($webp) / max(1, $downloadedBytes) * 100)) . '%',
                ], $yfId);
                $bytes = $webp;
                $realExt = 'webp';
            }
        }

        $safeName = FormatSniffer::rename_to(sanitize_file_name($filename), $realExt);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $upload = wp_upload_bits($safeName, null, $bytes);
        if (!empty($upload['error'])) {
            $this->log->warn('media', 'wp_upload_bits failed: ' . (string) $upload['error'], ['filename' => $safeName], $yfId);
            return 0;
        }

        $attachmentId = wp_insert_attachment([
            'post_mime_type' => FormatSniffer::mime_for($realExt),
            'post_title'     => $file['title'] !== null && $file['title'] !== '' ? $file['title'] : pathinfo($safeName, PATHINFO_FILENAME),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $upload['file'], $postId ?: 0, true);

        if (is_wp_error($attachmentId)) {
            $this->log->warn('media', 'wp_insert_attachment failed: ' . $attachmentId->get_error_message(), ['filename' => $safeName], $yfId);
            @unlink($upload['file']);
            return 0;
        }

        $attachmentId = (int) $attachmentId;
        $metadata = wp_generate_attachment_metadata($attachmentId, $upload['file']);
        if (is_array($metadata)) {
            $metadata = $this->drop_oversized_original($metadata, $upload['file'], $yfId);
            wp_update_attachment_metadata($attachmentId, $metadata);
        }

        $alt = $file['title'] !== null && $file['title'] !== ''
            ? (string) $file['title']
            : trim($yachtName . ' – ' . $gallery . ' ' . ($index + 1));
        update_post_meta($attachmentId, '_wp_attachment_image_alt', $alt);

        // Provenance only: the source URL embeds the passkey and is never stored.
        update_post_meta($attachmentId, '_oy_yf_id_file', $idFile);
        update_post_meta($attachmentId, '_oy_yf_yacht', $yfId);
        update_post_meta($attachmentId, '_oy_yf_gallery', $gallery);

        $this->ledger->record($idFile, $attachmentId, $yfId, $gallery, $filename, $realExt, strlen($bytes));

        return $attachmentId;
    }

    /**
     * Removes the untouched original WordPress keeps beside the `-scaled` copy.
     *
     * When a source image is wider than `big_image_size_threshold` (2560px),
     * WordPress serves a scaled copy and keeps the original on disk for ever,
     * in case someone wants to regenerate from it.
     *
     * For feed images that second copy buys nothing and costs a great deal.
     * Measured over three yachts (82 images): the retained originals were
     * 54.09 MB of 101.81 MB — **53% of all bytes for files that are never
     * served**. Yachtfolio sends 4192–8192px images (median 6412); everything
     * on the site renders from the 2560px scaled copy or smaller.
     *
     * Safe because the original is not the only copy: it can be re-fetched from
     * Yachtfolio at any time. Only files this importer created are touched —
     * images uploaded by an editor keep their originals.
     *
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function drop_oversized_original(array $metadata, string $scaledPath, int $yfId): array
    {
        if (!$this->settings->bool('drop_oversized_originals')) {
            return $metadata;
        }

        $original = (string) ($metadata['original_image'] ?? '');
        if ($original === '') {
            return $metadata;
        }

        $path = trailingslashit(dirname($scaledPath)) . $original;

        // Never delete the file actually being served.
        if ($path === $scaledPath || !is_file($path)) {
            return $metadata;
        }

        $freed = (int) filesize($path);
        if (@unlink($path)) {
            unset($metadata['original_image']);
            $this->log->info('media', 'dropped the unserved full-size original', [
                'file'  => basename($path),
                'freed' => $freed,
            ], $yfId);
        }

        return $metadata;
    }

    /**
     * Writes the `gallery` CSV (no trailing comma, matching existing content),
     * the LAYOUT list and the featured image.
     *
     * OWNERSHIP APPLIES HERE TOO
     * --------------------------
     * This method used to call update_post_meta() straight out, bypassing the
     * gate every mapped field goes through. On a hand-built yacht that is
     * destructive: MAIA (post 3211) carries 47 hand-picked images, the ledger
     * holds 3 feed images for it, and the next run would have replaced the 47
     * with the 3. That is the same class of incident the 2026-08-30 work order
     * had to restore from a backup — mapped fields were protected then, this
     * write was missed because it does not go through Writer.
     *
     * So: a manual yacht keeps its gallery unless the field is empty. A feed
     * yacht is owned by the feed and is written normally. Refusals are logged,
     * never silent.
     */
    public function sync_gallery_meta(int $postId, int $yfId): void
    {
        if ($postId <= 0) {
            return;
        }

        $mode = (string) $this->settings->get('write_mode', 'fill_empty_only');
        $protect = Ownership::origin_of($postId) === Ownership::ORIGIN_MANUAL && $mode !== 'authoritative';

        $put = function (string $key, string $value) use ($postId, $protect, $yfId): void {
            $current = (string) get_post_meta($postId, $key, true);
            if ($current === $value) {
                return;
            }
            if ($protect && trim($current) !== '') {
                $this->log->warn('media', 'protected: hand-entered yacht, field is not empty', [
                    'field'   => $key,
                    'kept'    => substr_count($current, ',') + 1 . ' item(s)',
                    'refused' => substr_count($value, ',') + 1 . ' item(s)',
                ], $yfId);
                return;
            }
            update_post_meta($postId, $key, $value);
        };

        $buckets = (array) $this->settings->get('media_galleries', ['FULL', 'LAYOUT']);
        $galleryIds = $this->ledger->attachments_for($yfId, array_map('strtoupper', $buckets));

        if ($galleryIds !== []) {
            $put('gallery', MetaFormat::gallery($galleryIds));
        }

        $layoutIds = $this->ledger->attachments_for($yfId, ['LAYOUT']);
        if ($layoutIds !== []) {
            $put('yf_layout_gallery', MetaFormat::gallery($layoutIds));
        }

        // Already conditional on there being no thumbnail, so it cannot displace
        // a hand-chosen one.
        if ($this->settings->bool('set_featured_image', true) && $galleryIds !== []) {
            if ((int) get_post_thumbnail_id($postId) <= 0) {
                set_post_thumbnail($postId, $galleryIds[0]);
            }
        }

        /**
         * Refresh the presence flag here, because this is the only code that
         * changes `gallery`.
         *
         * Writer::store_presence_flags() computes yf_has_gallery during the
         * mapped write, but media arrives afterwards in a separate job — so on a
         * first import the flag stayed empty while 30 images sat in the field,
         * and any template condition on {cf_yf_has_gallery} hid a gallery that
         * existed. Only an end-to-end run shows this.
         */
        $gallery = trim((string) get_post_meta($postId, 'gallery', true));
        $count = $gallery === '' ? 0 : count(array_filter(array_map('trim', explode(',', $gallery))));
        update_post_meta($postId, 'yf_has_gallery', $count > 0 ? (string) $count : '');
    }

    /**
     * @param array{imported:int,skipped:int,failed:int,ids:array<int,int>} $result
     * @return array{imported:int,skipped:int,failed:int,ids:array<int,int>}
     */
    private function import_crew_photos(YachtPayload $payload, int $postId, array $result): array
    {
        $cap = $this->settings->int('media_cap_per_yacht', 60);
        $rows = [];
        $downloaded = 0;

        foreach ($payload->crew as $index => $member) {
            $photo = $member['photo'] ?? null;
            if (!is_array($photo) || empty($photo['filename'])) {
                continue;
            }

            $idFile = (string) ($photo['id_file'] ?? ('crew:' . $payload->yf_id . ':' . $index));

            // Ledger hit: reuse the attachment, count it as skipped and make no
            // HTTP call. Counting it as "imported" made a warm re-run look like
            // a duplicate download.
            $existing = $this->ledger->find($idFile);
            if ($existing !== null && $this->ledger->attachment_exists((int) $existing['attachment_id'])) {
                $result['skipped']++;
                $rows[$index] = (int) $existing['attachment_id'];
                continue;
            }

            if ($cap > 0 && $downloaded >= $cap) {
                break; // the per-yacht cap covers crew portraits too
            }

            try {
                $attachmentId = $this->import_file(
                    [
                        'id_file'  => $idFile,
                        'filename' => (string) $photo['filename'],
                        'title'    => trim((string) $member['first_name'] . ' ' . (string) $member['last_name']),
                        'id_order' => $index,
                    ],
                    $payload->yf_id,
                    'CREW',
                    $postId,
                    $payload->name,
                    $index
                );
                if ($attachmentId > 0) {
                    $result['imported']++;
                    $downloaded++;
                    $rows[$index] = $attachmentId;
                } else {
                    $result['failed']++;
                }
            } catch (ApiError $e) {
                if (in_array($e->kind, [ApiError::BUDGET, ApiError::RATELIMIT], true)) {
                    throw $e;
                }
                $result['failed']++;
            }
        }

        if ($rows !== []) {
            ksort($rows);
            update_post_meta($postId, 'yf_crew_photos', MetaFormat::gallery(array_values($rows)));
        }

        return $result;
    }
}
