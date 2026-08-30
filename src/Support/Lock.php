<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Support;

/**
 * Single-flight guard stored in options (not transients) so an object-cache
 * flush cannot silently release it mid-run.
 */
final class Lock
{
    private const PREFIX = 'oy_yf_lock_';

    public function acquire(string $name, int $ttl = 900): bool
    {
        $key = self::PREFIX . $name;
        $now = time();
        $current = get_option($key);

        if (is_array($current) && isset($current['expires']) && (int) $current['expires'] > $now) {
            return false;
        }

        // add_option() is atomic enough for our single-writer cron/CLI usage;
        // update_option() covers the expired-lock case.
        $value = ['owner' => wp_generate_uuid4(), 'expires' => $now + max(30, $ttl)];
        if ($current === false) {
            return add_option($key, $value, '', false) || $this->force($key, $value);
        }
        return $this->force($key, $value);
    }

    /** @param array{owner:string,expires:int} $value */
    private function force(string $key, array $value): bool
    {
        update_option($key, $value, false);
        return true;
    }

    public function release(string $name): void
    {
        delete_option(self::PREFIX . $name);
    }

    public function held(string $name): bool
    {
        $current = get_option(self::PREFIX . $name);
        return is_array($current) && isset($current['expires']) && (int) $current['expires'] > time();
    }
}
