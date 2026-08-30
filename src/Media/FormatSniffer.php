<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Media;

/**
 * Magic-byte format detection for everything that comes out of `media/res`.
 *
 * Why this class exists (measured on the live key, finding K12):
 * Yachtfolio serves **WebP bytes under a `.jpg` filename** and answers with
 * `content-type: image/jpeg`. Example: `ODBRtfYgI-Vl.jpg` downloads as
 * `RIFF....WEBP`. So neither the filename nor the HTTP header may be trusted —
 * if we sideload those bytes as `.jpg`, WordPress stores a file whose extension
 * contradicts its content, image sub-sizes are generated from a mislabelled
 * source and some CDNs / browsers refuse it outright.
 *
 * Rule for the whole plugin: sniff the bytes, rename to the real extension via
 * {@see self::rename_to()}, and only then hand anything to WordPress.
 *
 * Gallery `PDF` from the API is *not* documents — every entry is a `.jpg` that
 * also lives in `FULL`. The only genuine document in the feed is `sample_menu`.
 */
final class FormatSniffer
{
    /** Extensions we are willing to put into the media library. */
    public const ALLOWED = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'pdf'];

    /** @var array<string,string> */
    private const MIMES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif'  => 'image/gif',
        'pdf'  => 'application/pdf',
    ];

    /**
     * ISO-BMFF brands that mean "AVIF still image or sequence".
     *
     * @var array<int,string>
     */
    private const AVIF_BRANDS = ['avif', 'avis', 'avio'];

    /**
     * Real format of a byte string.
     *
     * @return string one of jpg|png|webp|avif|gif|pdf, or '' when unrecognised
     */
    public static function sniff_bytes(string $bytes): string
    {
        if (strlen($bytes) < 4) {
            return '';
        }

        // JPEG: FF D8 FF
        if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") {
            return 'jpg';
        }

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (substr($bytes, 0, 8) === "\x89PNG\r\n\x1A\n") {
            return 'png';
        }

        // GIF: "GIF8" (87a and 89a alike)
        if (substr($bytes, 0, 4) === 'GIF8') {
            return 'gif';
        }

        // RIFF container: "RIFF" ....size.... "WEBP"
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }

        // ISO-BMFF: ....size.... "ftyp" <brand>
        if (substr($bytes, 4, 4) === 'ftyp') {
            $brand = strtolower(substr($bytes, 8, 4));
            if (in_array($brand, self::AVIF_BRANDS, true)) {
                return 'avif';
            }
            // Some encoders put the AVIF brand in the compatible-brands list only.
            $compatible = strtolower(substr($bytes, 16, 32));
            foreach (self::AVIF_BRANDS as $candidate) {
                if (str_contains($compatible, $candidate)) {
                    return 'avif';
                }
            }
        }

        // PDF: "%PDF", occasionally behind a BOM or stray whitespace.
        $head = substr($bytes, 0, 1024);
        $offset = strpos($head, '%PDF');
        if ($offset !== false && $offset <= 4) {
            return 'pdf';
        }

        return '';
    }

    /** Real format of a file already on disk. Returns '' when unreadable. */
    public static function sniff_file(string $path): string
    {
        if ($path === '' || !is_readable($path)) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return '';
        }

        $head = (string) fread($handle, 1024);
        fclose($handle);

        return self::sniff_bytes($head);
    }

    /** Lower-cased extension claimed by a filename ('' when it has none). */
    public static function extension_of(string $filename): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        $base = (string) strtok($base, '?');
        $dot = strrpos($base, '.');
        if ($dot === false || $dot === strlen($base) - 1) {
            return '';
        }

        return strtolower(substr($base, $dot + 1));
    }

    /**
     * Swap the extension while keeping the base name.
     *
     * rename_to('ODBRtfYgI-Vl.jpg', 'webp') === 'ODBRtfYgI-Vl.webp'
     */
    public static function rename_to(string $filename, string $ext): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        $base = (string) strtok($base, '?');
        $ext = ltrim(strtolower(trim($ext)), '.');

        if ($base === '') {
            return $ext === '' ? '' : 'file.' . $ext;
        }
        if ($ext === '') {
            return $base;
        }

        $dot = strrpos($base, '.');
        $stem = ($dot === false || $dot === 0) ? $base : substr($base, 0, $dot);
        if ($stem === '') {
            $stem = 'file';
        }

        return $stem . '.' . $ext;
    }

    /** MIME type for an extension, '' when we do not accept it. */
    public static function mime_for(string $ext): string
    {
        return self::MIMES[strtolower(ltrim($ext, '.'))] ?? '';
    }

    public static function is_allowed(string $ext): bool
    {
        return in_array(strtolower(ltrim($ext, '.')), self::ALLOWED, true);
    }
}
