<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Reference;

/**
 * Operating areas: 26 groups, 107 areas on the live feed. Areas point at their
 * group through parent_id.
 */
final class AreaResolver
{
    /** @var array<int,string>|null */
    private ?array $areaNames = null;

    /** @var array<int,int>|null area id => group id */
    private ?array $areaParent = null;

    /** @var array<int,string>|null */
    private ?array $groupNames = null;

    public function __construct(private ReferenceCache $reference)
    {
    }

    public function area_name(int $id): ?string
    {
        $this->build();
        return $this->areaNames[$id] ?? null;
    }

    public function group_name_of_area(int $areaId): ?string
    {
        $this->build();
        $groupId = $this->areaParent[$areaId] ?? null;
        return $groupId !== null ? ($this->groupNames[$groupId] ?? null) : null;
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,string> names in input order, unknown ids skipped
     */
    public function names(array $ids): array
    {
        $this->build();
        $out = [];
        foreach ($ids as $id) {
            $name = $this->areaNames[(int) $id] ?? null;
            if ($name !== null && $name !== '' && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * @param array<int,int> $areaIds
     * @return array<int,string> unique group names
     */
    public function group_names(array $areaIds): array
    {
        $this->build();
        $out = [];
        foreach ($areaIds as $id) {
            $group = $this->group_name_of_area((int) $id);
            if ($group !== null && $group !== '' && !in_array($group, $out, true)) {
                $out[] = $group;
            }
        }
        return $out;
    }

    /** @return array<int,string> */
    public function all_areas(): array
    {
        $this->build();
        return $this->areaNames ?? [];
    }

    /** @return array<int,string> */
    public function all_groups(): array
    {
        $this->build();
        return $this->groupNames ?? [];
    }

    private function build(): void
    {
        if ($this->areaNames !== null) {
            return;
        }

        $this->areaNames = [];
        $this->areaParent = [];
        $this->groupNames = [];

        $table = $this->reference->areas();

        foreach ($table['groups'] as $group) {
            if (isset($group['id'])) {
                $this->groupNames[(int) $group['id']] = trim((string) ($group['group_name'] ?? $group['name'] ?? ''));
            }
        }

        foreach ($table['areas'] as $area) {
            if (!isset($area['id'])) {
                continue;
            }
            $id = (int) $area['id'];
            $this->areaNames[$id] = trim((string) ($area['area_name'] ?? $area['name'] ?? ''));
            if (isset($area['parent_id']) && is_numeric($area['parent_id'])) {
                $this->areaParent[$id] = (int) $area['parent_id'];
            }
        }
    }
}
