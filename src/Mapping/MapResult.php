<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Mapping;

/**
 * What the mapper produced for one yacht, before anything touches the database.
 *
 * `meta` holds RAW values (bool for switchers, list-of-rows for repeaters,
 * scalars otherwise); storage formatting happens in Write\MetaFormat.
 */
final class MapResult
{
    /**
     * @param array<string,string> $post
     * @param array<string,mixed> $meta
     * @param array<string,array<int,string>> $terms
     * @param array<int,string> $flags
     */
    public function __construct(
        public array $post,
        public array $meta,
        public array $terms,
        public MappingReport $report,
        public array $flags = []
    ) {
    }

    public function flag(string $flag): void
    {
        if (!in_array($flag, $this->flags, true)) {
            $this->flags[] = $flag;
        }
    }

    public function has_flag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    public function flags_csv(): string
    {
        return implode(',', $this->flags);
    }
}
