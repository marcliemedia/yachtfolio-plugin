<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Reference;

/**
 * Season lookup. Live data: id 29 = Summer 2026 (2026-05-01 → 2026-09-30),
 * 30 = Winter 2026/2027, 31 = Summer 2027.
 */
final class SeasonResolver
{
    /** @var array<int,array<string,mixed>>|null */
    private ?array $byId = null;

    public function __construct(private ReferenceCache $reference)
    {
    }

    /** @return array<string,mixed>|null */
    public function by_id(int $id): ?array
    {
        return $this->index()[$id] ?? null;
    }

    /** @return array<int,array<string,mixed>> sorted by season_start */
    public function all(): array
    {
        $rows = array_values($this->index());
        usort($rows, static fn(array $a, array $b): int => strcmp((string) ($a['season_start'] ?? ''), (string) ($b['season_start'] ?? '')));
        return $rows;
    }

    /**
     * The season covering the given moment.
     *
     * @return array<string,mixed>|null
     */
    public function current(?int $timestamp = null): ?array
    {
        $date = gmdate('Y-m-d', $timestamp ?? time());
        foreach ($this->all() as $season) {
            $start = (string) ($season['season_start'] ?? '');
            $end   = (string) ($season['season_end'] ?? '');
            if ($start !== '' && $end !== '' && $start <= $date && $date <= $end) {
                return $season;
            }
        }
        return null;
    }

    /**
     * The next season after the current one (or after the given moment when
     * nothing is current).
     *
     * @return array<string,mixed>|null
     */
    public function next(?int $timestamp = null): ?array
    {
        $date = gmdate('Y-m-d', $timestamp ?? time());
        $current = $this->current($timestamp);
        $after = $current !== null ? (string) $current['season_start'] : $date;

        foreach ($this->all() as $season) {
            $start = (string) ($season['season_start'] ?? '');
            if ($start !== '' && $start > $after) {
                return $season;
            }
        }
        return null;
    }

    /** summer|winter|'' */
    public function kind(int $id): string
    {
        $season = $this->by_id($id);
        $name = strtolower((string) ($season['name'] ?? ''));
        if (str_contains($name, 'summer')) {
            return 'summer';
        }
        if (str_contains($name, 'winter')) {
            return 'winter';
        }
        return '';
    }

    /** e.g. "Summer 2026" */
    public function label(int $id): string
    {
        $season = $this->by_id($id);
        if ($season === null) {
            return sprintf('Season %d', $id);
        }
        $name = trim((string) ($season['name'] ?? ''));
        $year = trim((string) ($season['year'] ?? ''));
        return trim($name . ' ' . $year);
    }

    /** e.g. "1. svi – 30. ruj 2026" using the site locale. */
    public function window_text(int $id): string
    {
        $season = $this->by_id($id);
        if ($season === null) {
            return '';
        }
        $start = strtotime((string) ($season['season_start'] ?? '') . ' 00:00:00 UTC');
        $end   = strtotime((string) ($season['season_end'] ?? '') . ' 00:00:00 UTC');
        if ($start === false || $end === false) {
            return '';
        }

        $from = wp_date('j. M', $start, new \DateTimeZone('UTC'));
        $to   = wp_date('j. M Y', $end, new \DateTimeZone('UTC'));

        return trim((string) $from) . ' – ' . trim((string) $to);
    }

    /** @return array<int,array<string,mixed>> */
    private function index(): array
    {
        if ($this->byId === null) {
            $this->byId = [];
            foreach ($this->reference->seasons() as $season) {
                if (isset($season['id'])) {
                    $this->byId[(int) $season['id']] = $season;
                }
            }
        }
        return $this->byId;
    }
}
