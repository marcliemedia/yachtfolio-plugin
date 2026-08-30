<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Media;

/**
 * Re-encodes a downloaded image to WebP before it is written to disk.
 *
 * WHY THIS EXISTS INSTEAD OF LEANING ON THE SITE'S WEBP PLUGIN
 * -----------------------------------------------------------
 * WebP Converter for Media 6.6.5 is installed and configured
 * (`auto_conversion: yes`, quality 85, `only_smaller`) but has converted
 * nothing: a scan of all 12,663 files under uploads/ on 2026-08-30 found
 * **zero** `X.jpg.webp` companions, while 1,061 JPGs (388.6 MB) sat waiting.
 *
 * Two reasons, both structural rather than a misconfiguration we could fix from
 * here:
 *   1. Its on-upload path collects paths and then fires a NON-BLOCKING loopback
 *      POST (`timeout: 0.01`, `blocking: false`) at its own REST endpoint. Under
 *      WP-CLI — which is how a sync runs — nothing guarantees that request is
 *      even sent before the process exits.
 *   2. Its background sweep is a cron event that is only scheduled when
 *      `cron_enabled` is present in the plugin's `features` option. On this site
 *      `features` is `["only_smaller"]`, so `webpc_cron_paths` is never
 *      scheduled and the queue it fills is never drained.
 *
 * Converting in-process removes both moving parts: the bytes are already in
 * memory, the result is deterministic, and nothing depends on cron, a loopback
 * request, or a third-party plugin staying configured.
 *
 * Yachtfolio also labels every file `.jpg` regardless of content — of the 13
 * files imported for MAIA, the 3 gallery images were already WebP and the 10
 * crew photos were real JPEG averaging 2.37 MB. So this only ever has work to do
 * on the files that genuinely need it.
 */
final class WebpEncoder
{
    /** Mirrors the site's WebP Converter setting so output is consistent. */
    public const DEFAULT_QUALITY = 85;

    /**
     * Decoding costs roughly width × height × 4 bytes in GD. Above this the
     * image is left alone rather than risking the whole import on an OOM.
     */
    private const MAX_PIXELS = 40_000_000;

    /** Formats worth re-encoding. webp and avif are already efficient. */
    private const CONVERTIBLE = ['jpg', 'jpeg', 'png'];

    public static function available(): bool
    {
        return function_exists('imagewebp')
            && function_exists('imagecreatefromstring')
            && (imagetypes() & IMG_WEBP) === IMG_WEBP;
    }

    public static function convertible(string $ext): bool
    {
        return in_array(strtolower($ext), self::CONVERTIBLE, true);
    }

    /**
     * WebP bytes, or null when conversion is impossible or not worth it.
     *
     * Returning null on a larger result mirrors the site's `only_smaller`
     * setting: a WebP that is bigger than the JPEG it replaces is a regression,
     * and it does happen on already well-compressed photographs.
     */
    public static function encode(string $bytes, string $ext, int $quality = self::DEFAULT_QUALITY): ?string
    {
        if ($bytes === '' || !self::convertible($ext) || !self::available()) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
            return null;
        }
        if ((int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        try {
            // PNGs arrive paletted or with alpha; WebP keeps transparency, but GD
            // needs to be told to preserve it rather than flattening to black.
            if (strtolower($ext) === 'png') {
                if (!imageistruecolor($image)) {
                    imagepalettetotruecolor($image);
                }
                imagealphablending($image, false);
                imagesavealpha($image, true);
            }

            ob_start();
            $ok = imagewebp($image, null, max(1, min(100, $quality)));
            $out = (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        if (!$ok || $out === '') {
            return null;
        }

        // A WebP that is not smaller is not an improvement.
        return strlen($out) < strlen($bytes) ? $out : null;
    }
}
