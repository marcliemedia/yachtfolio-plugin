<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Mapping;

/**
 * Equipment id => amenity switcher meta key.
 *
 * The live `equipments` table has exactly 10 entries:
 *   1 Air conditioning, 2 Stabilisers at anchor, 3 WiFi connection,
 *   4 Deck Jacuzzi, 5 Gym/exercise equipment, 6 Elevator/lift,
 *   7 Wheelchair accessibility, 8 Stabilisers underway,
 *   9 Approved RYA water sports centre, 10 Helipad
 *
 * Only four of them have a counterpart among the site's 35 `amenities_*`
 * switchers. The other 31 switchers therefore have NO API source and stay
 * editor-owned: writing 'false' into them would erase live content.
 */
final class EquipmentMap
{
    public const OPTION = 'oy_yf_equipment_map';

    /** @return array<int,string> */
    public static function defaults(): array
    {
        return [
            1 => 'amenities_ac',
            3 => 'amenities_wifi',
            4 => 'amenities_jacuzzi',
            5 => 'amenities_gym-equipment',
        ];
    }

    /** @return array<int,string> */
    public static function stored(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return [];
        }
        $out = [];
        foreach ($stored as $id => $key) {
            if (is_numeric($id) && is_string($key) && $key !== '') {
                $out[(int) $id] = $key;
            }
        }
        return $out;
    }

    /** @return array<int,string> */
    public static function effective(): array
    {
        return self::stored() !== [] ? self::stored() + self::defaults() : self::defaults();
    }

    /** @param array<int|string,string> $map */
    public static function save(array $map): void
    {
        $clean = [];
        foreach ($map as $id => $key) {
            $id = (int) $id;
            $key = sanitize_key((string) $key);
            if ($id > 0 && $key !== '') {
                $clean[$id] = $key;
            }
        }
        update_option(self::OPTION, $clean, false);
    }

    public static function reset(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * Switcher values for the mapped keys only.
     *
     * @param array<int,int> $equipmentIds
     * @return array<string,bool> meta key => on/off
     */
    public static function switches(array $equipmentIds): array
    {
        $ids = array_map('intval', $equipmentIds);
        $out = [];
        foreach (self::effective() as $equipmentId => $metaKey) {
            $out[$metaKey] = in_array((int) $equipmentId, $ids, true);
        }
        return $out;
    }

    /**
     * Equipment ids the feed sent that nothing maps to — reported, never
     * silently dropped.
     *
     * @param array<int,int> $equipmentIds
     * @return array<int,int>
     */
    public static function unmapped(array $equipmentIds): array
    {
        $mapped = array_keys(self::effective());
        $out = [];
        foreach ($equipmentIds as $id) {
            if (!in_array((int) $id, $mapped, true)) {
                $out[] = (int) $id;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return array<int,string> amenity meta keys driven by the feed */
    public static function mapped_amenity_keys(): array
    {
        return array_values(self::effective());
    }
}
