<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Mapping;

/**
 * Collects every value the mapper could not place. Nothing is dropped in
 * silence: the Mapping screen shows these so the operator can add an alias.
 */
final class MappingReport
{
    public const OPTION = 'oy_yf_last_unmapped';

    public const KIND_EQUIPMENT = 'equipment';
    public const KIND_AREA      = 'area';
    public const KIND_TYPE      = 'type';

    /** @var array<string,array<string,int>> kind => value => count */
    private array $items = [];

    /** @var array<string,array<int,int>> kind => yacht ids */
    private array $yachts = [];

    public function add(string $kind, string $value, ?int $yfId = null): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        $this->items[$kind][$value] = ($this->items[$kind][$value] ?? 0) + 1;
        if ($yfId !== null) {
            $this->yachts[$kind][$yfId] = $yfId;
        }
    }

    /** @return array<string,array<string,int>> */
    public function all(): array
    {
        return $this->items;
    }

    /** @return array<int,int> */
    public function yachts_for(string $kind): array
    {
        return array_values($this->yachts[$kind] ?? []);
    }

    public function is_empty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        $total = 0;
        foreach ($this->items as $values) {
            $total += array_sum($values);
        }
        return $total;
    }

    public function merge(self $other): void
    {
        foreach ($other->items as $kind => $values) {
            foreach ($values as $value => $count) {
                $this->items[$kind][$value] = ($this->items[$kind][$value] ?? 0) + $count;
            }
        }
        foreach ($other->yachts as $kind => $ids) {
            foreach ($ids as $id) {
                $this->yachts[$kind][$id] = $id;
            }
        }
    }

    public function persist(?string $runId = null): void
    {
        update_option(self::OPTION, [
            'run_id'       => (string) $runId,
            'generated_at' => gmdate('c'),
            'items'        => $this->items,
            'yachts'       => array_map('array_values', $this->yachts),
        ], false);
    }

    public static function load(): self
    {
        $report = new self();
        $stored = get_option(self::OPTION);
        if (!is_array($stored) || !is_array($stored['items'] ?? null)) {
            return $report;
        }
        foreach ($stored['items'] as $kind => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $value => $count) {
                $report->items[(string) $kind][(string) $value] = (int) $count;
            }
        }
        foreach ((array) ($stored['yachts'] ?? []) as $kind => $ids) {
            foreach ((array) $ids as $id) {
                $report->yachts[(string) $kind][(int) $id] = (int) $id;
            }
        }
        return $report;
    }

    /** @return array{run_id:string,generated_at:string} */
    public static function meta(): array
    {
        $stored = get_option(self::OPTION);
        return [
            'run_id'       => is_array($stored) ? (string) ($stored['run_id'] ?? '') : '',
            'generated_at' => is_array($stored) ? (string) ($stored['generated_at'] ?? '') : '',
        ];
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }
}
