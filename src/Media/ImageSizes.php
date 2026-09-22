<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Media;

use Otium\Yachtfolio\Support\Settings;

/**
 * Stops WordPress generating derivative sizes nothing renders from.
 *
 * Measured on this site (yacht 4A, 36 imported images → 213 files, 18.29 MB):
 * the derivatives are 68% of the bytes, and two of them earn none of it.
 *
 *   1536x1536 → 1536x1024, 3.92 MB across 34 files
 *   2048x2048 → 2048x1343, 0.53 MB across 5 files
 *
 * `large` on this site is 1620x1080, so 1536 duplicates it within 5% of width.
 * Both appear only inside `srcset` — never in a `src` attribute (0 of 125
 * checked on the front page) — so dropping them costs one candidate in the
 * responsive set, with 1620 sitting in the same place.
 *
 * Only new uploads are affected: files already on disk keep their metadata and
 * their srcset entries, so nothing that currently renders changes.
 *
 * Scale of the saving on a full 433-yacht import: 2.30 GB and 20,639 files.
 */
final class ImageSizes
{
    /**
     * Sizes WordPress adds by default and this theme never selects.
     *
     * @var array<int,string>
     */
    private const UNUSED = ['1536x1536', '2048x2048'];

    public function __construct(private Settings $settings)
    {
    }

    public function register(): void
    {
        // NOT admin-only: media is imported from Action Scheduler, which runs
        // in a cron request with no admin context.
        add_filter('intermediate_image_sizes_advanced', [$this, 'drop_unused'], 10, 1);
    }

    /**
     * @param array<string,array<string,mixed>> $sizes
     * @return array<string,array<string,mixed>>
     */
    public function drop_unused(array $sizes): array
    {
        if (!$this->settings->bool('trim_image_sizes')) {
            return $sizes;
        }

        foreach (self::UNUSED as $name) {
            unset($sizes[$name]);
        }

        return $sizes;
    }

    /** @return array<int,string> */
    public static function dropped(): array
    {
        return self::UNUSED;
    }
}
