<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Domain;

use Otium\Yachtfolio\Support\Secrets;

/**
 * Turns the two API shapes into one payload.
 *
 * Brochure (api_brochure.cgi) is the universal source: it answers for every
 * yacht in the index. The bulk record (api_basic.cgi?type=yachts) exists only
 * for the yachts the key owns and is the only source of equipment ids, the full
 * historical rate table, licences, special requests and unavailable seasons.
 */
final class Normalizer
{
    /**
     * Bulk field name => canonical spec key used by the mapper.
     */
    private const BULK_ALIASES = [
        'length_metric' => 'length_metres',
        'beam_metric'   => 'beam_metres',
        'draft_metric'  => 'draft_metres',
    ];

    private const GALLERY_BUCKETS = ['FULL', 'EXTERIOR', 'INTERIOR', 'LIFESTYLE', 'LAYOUT', 'PDF'];

    /**
     * @param array<string,mixed> $brochure
     * @param array<string,mixed>|null $bulk
     */
    public function from(
        int $yfId,
        string $name,
        string $registryPort,
        array $brochure,
        ?array $bulk,
        string $lastModified
    ): YachtPayload {
        $spec = $this->spec($brochure, $bulk);
        $general = is_array($brochure['general'] ?? null) ? $brochure['general'] : [];

        return new YachtPayload(
            yf_id: $yfId,
            name: $name !== '' ? $name : (string) ($bulk['yacht_name'] ?? ''),
            registry_port: $registryPort !== '' ? $registryPort : (string) ($spec['registry_port'] ?? ''),
            spec: $spec,
            description: trim((string) ($general['general_description'] ?? '')),
            key_features: $this->key_features($brochure),
            crew: $this->crew($brochure),
            video: $this->video($brochure),
            galleries: $this->galleries($brochure),
            rates: $this->rates($brochure, $bulk),
            areas_by_season: $this->areas($brochure, $bulk),
            equipment: $this->int_list($bulk['equipment'] ?? []),
            special_requests: $this->special_requests($bulk),
            licences: $this->licences($bulk),
            seasons_unavailable: $this->int_list($bulk['seasons_unavailable'] ?? []),
            broker: $this->broker($brochure),
            sample_menu: $this->sample_menu($brochure),
            owned: is_array($bulk) && $bulk !== [],
            last_modified: $lastModified !== '' ? $lastModified : (string) ($brochure['last_modified'] ?? ''),
            raw: [
                'brochure' => (array) Secrets::scrub($brochure),
                'bulk'     => is_array($bulk) ? (array) Secrets::scrub($bulk) : null,
            ],
        );
    }

    /**
     * @param array<string,mixed> $brochure
     * @param array<string,mixed>|null $bulk
     * @return array<string,mixed>
     */
    private function spec(array $brochure, ?array $bulk): array
    {
        $spec = is_array($brochure['specifications'] ?? null) ? $brochure['specifications'] : [];

        // The "auto|manual|manual_text" block repeats a few converted values.
        $variant = is_array($brochure['auto'] ?? null) ? $brochure['auto'] : [];
        foreach ($variant as $key => $value) {
            if (!array_key_exists($key, $spec) || $spec[$key] === null || $spec[$key] === '') {
                $spec[$key] = $value;
            }
        }

        foreach (is_array($bulk) ? $bulk : [] as $key => $value) {
            if (is_array($value)) {
                continue; // structured blocks are carried as their own payload fields
            }
            $canonical = self::BULK_ALIASES[$key] ?? $key;
            // Bulk is the vendor's own record: it wins where it carries a value.
            if ($value !== null && $value !== '') {
                $spec[$canonical] = $value;
            } elseif (!array_key_exists($canonical, $spec)) {
                $spec[$canonical] = $value;
            }
            if ($canonical !== $key && !array_key_exists($key, $spec)) {
                $spec[$key] = $value;
            }
        }

        // Port objects arrive as {name,country} on the bulk record.
        foreach (['summer_port_location', 'winter_port_location'] as $key) {
            if (isset($bulk[$key]) && is_array($bulk[$key])) {
                $parts = array_filter([
                    (string) ($bulk[$key]['name'] ?? ''),
                    (string) ($bulk[$key]['country'] ?? ''),
                ]);
                $spec[$key] = implode(', ', $parts);
            }
        }

        return $spec;
    }

    /**
     * @param array<string,mixed> $brochure
     * @return array<int,string>
     */
    private function key_features(array $brochure): array
    {
        $out = [];
        foreach ((array) ($brochure['key_features'] ?? []) as $row) {
            $content = is_array($row) ? (string) ($row['content'] ?? '') : (string) $row;
            $content = trim($content);
            if ($content !== '') {
                $out[] = $content;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $brochure
     * @return array<int,array<string,mixed>>
     */
    private function crew(array $brochure): array
    {
        $out = [];
        foreach ((array) ($brochure['crew_members'] ?? []) as $member) {
            if (!is_array($member)) {
                continue;
            }
            $first = trim((string) ($member['first_name'] ?? ''));
            $last  = trim((string) ($member['last_name'] ?? ''));
            $position = trim((string) ($member['position'] ?? ''));
            if ($first === '' && $last === '' && $position === '') {
                continue;
            }
            $photo = $member['photo'] ?? null;
            $out[] = [
                'first_name'  => $first,
                'last_name'   => $last,
                'position'    => $position,
                'nationality' => trim((string) ($member['nationality'] ?? '')),
                'description' => trim((string) ($member['description'] ?? '')),
                'tba'         => (bool) ($member['tba'] ?? false),
                'photo'       => is_array($photo) ? $this->file($photo) : null,
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $brochure
     * @return array<string,mixed>
     */
    private function video(array $brochure): array
    {
        $video = is_array($brochure['video'] ?? null) ? $brochure['video'] : [];
        $url = trim((string) ($video['video_url'] ?? ''));
        if ($url === '') {
            return [];
        }
        return [
            'video_type' => trim((string) ($video['video_type'] ?? '')),
            'video_url'  => $url,
            'video_id'   => trim((string) ($video['video_id'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $brochure
     * @return array<string,array<int,array{id_file:string,filename:string,title:?string,id_order:?int}>>
     */
    private function galleries(array $brochure): array
    {
        $galleries = is_array($brochure['galleries'] ?? null) ? $brochure['galleries'] : [];
        $out = [];

        foreach ($galleries as $bucket => $files) {
            $bucket = strtoupper((string) $bucket);
            if (!in_array($bucket, self::GALLERY_BUCKETS, true) || !is_array($files)) {
                continue;
            }
            $rows = [];
            foreach ($files as $file) {
                if (!is_array($file)) {
                    continue;
                }
                $row = $this->file($file);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            usort($rows, static function (array $a, array $b): int {
                $ao = $a['id_order'];
                $bo = $b['id_order'];
                if ($ao === $bo) {
                    return strcmp($a['id_file'], $b['id_file']);
                }
                if ($ao === null) {
                    return 1;
                }
                if ($bo === null) {
                    return -1;
                }
                return $ao <=> $bo;
            });
            $out[$bucket] = $rows;
        }

        return $out;
    }

    /**
     * Never keeps `url`: the media URL embeds the passkey.
     *
     * @param array<string,mixed> $file
     * @return array{id_file:string,filename:string,title:?string,id_order:?int}|null
     */
    private function file(array $file): ?array
    {
        $filename = trim((string) ($file['filename'] ?? ''));
        if ($filename === '' && isset($file['url']) && is_string($file['url'])) {
            if (preg_match('/[?&]f=([^&]+)/', $file['url'], $m) === 1) {
                $filename = urldecode($m[1]);
            }
        }
        if ($filename === '') {
            return null;
        }

        $idFile = (string) ($file['id_file'] ?? '');
        if ($idFile === '') {
            $idFile = 'name:' . $filename;
        }

        $order = $file['id_order'] ?? null;

        return [
            'id_file'  => $idFile,
            'filename' => $filename,
            'title'    => isset($file['title']) && trim((string) $file['title']) !== '' ? trim((string) $file['title']) : null,
            'id_order' => is_numeric($order) ? (int) $order : null,
        ];
    }

    /**
     * Bulk `rates[]` (season_id + term, full history) beats brochure `prices`
     * (current seasons only, key `terms`).
     *
     * @param array<string,mixed> $brochure
     * @param array<string,mixed>|null $bulk
     * @return array<int,array{season_id:int,currency:string,min_rate:?float,max_rate:?float,term:string}>
     */
    private function rates(array $brochure, ?array $bulk): array
    {
        $rows = [];

        if (is_array($bulk['rates'] ?? null) && $bulk['rates'] !== []) {
            foreach ($bulk['rates'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rows[] = [
                    'season_id' => (int) ($row['season_id'] ?? 0),
                    'currency'  => strtoupper(trim((string) ($row['currency'] ?? ''))),
                    'min_rate'  => $this->amount($row['min_rate'] ?? null),
                    'max_rate'  => $this->amount($row['max_rate'] ?? null),
                    'term'      => trim((string) ($row['term'] ?? $row['terms'] ?? '')),
                ];
            }
        }

        if ($rows === []) {
            foreach ((array) ($brochure['prices'] ?? []) as $seasonId => $entries) {
                foreach (is_array($entries) ? $entries : [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $rows[] = [
                        'season_id' => (int) $seasonId,
                        'currency'  => strtoupper(trim((string) ($row['currency'] ?? ''))),
                        'min_rate'  => $this->amount($row['min_rate'] ?? null),
                        'max_rate'  => $this->amount($row['max_rate'] ?? null),
                        'term'      => trim((string) ($row['terms'] ?? $row['term'] ?? '')),
                    ];
                }
            }
        }

        usort($rows, static fn(array $a, array $b): int => $a['season_id'] <=> $b['season_id']);

        return array_values(array_filter($rows, static fn(array $r): bool => $r['season_id'] > 0));
    }

    private function amount(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $amount = (float) $value;
        return $amount > 0 ? $amount : null;
    }

    /**
     * @param array<string,mixed> $brochure
     * @param array<string,mixed>|null $bulk
     * @return array<int,array<int,int>>
     */
    private function areas(array $brochure, ?array $bulk): array
    {
        $out = [];

        foreach ((array) ($bulk['operating_areas_new'] ?? []) as $row) {
            if (is_array($row) && isset($row['season_id'])) {
                $out[(int) $row['season_id']] = $this->int_list($row['areas'] ?? []);
            }
        }

        if ($out === []) {
            foreach ((array) ($brochure['operating_areas'] ?? []) as $seasonId => $areas) {
                $out[(int) $seasonId] = $this->int_list($areas);
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed>|null $bulk
     * @return array<int,array<int,int>>
     */
    private function special_requests(?array $bulk): array
    {
        $out = [];
        foreach ((array) ($bulk['special_requests'] ?? []) as $row) {
            if (is_array($row) && isset($row['season_id'])) {
                $out[(int) $row['season_id']] = $this->int_list($row['special_requests'] ?? []);
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|null $bulk
     * @return array<int,array{licence_id:int,status_id:int,season_id:int,date_added:string}>
     */
    private function licences(?array $bulk): array
    {
        $out = [];
        foreach ((array) ($bulk['licences_registrations'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'licence_id' => (int) ($row['licence_id'] ?? 0),
                'status_id'  => (int) ($row['status_id'] ?? 0),
                'season_id'  => (int) ($row['season_id'] ?? 0),
                'date_added' => trim((string) ($row['date_added'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $brochure
     * @return array<string,string>
     */
    private function broker(array $brochure): array
    {
        $broker = is_array($brochure['broker'] ?? null) ? $brochure['broker'] : [];
        $out = [];
        foreach (['company_name', 'name', 'email', 'phone'] as $key) {
            $value = trim((string) ($broker[$key] ?? ''));
            if ($value !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $brochure
     * @return array{id_file:string,filename:string}|null
     */
    private function sample_menu(array $brochure): ?array
    {
        $menu = is_array($brochure['sample_menu'] ?? null) ? $brochure['sample_menu'] : [];
        if ($menu === []) {
            return null;
        }
        $file = $this->file($menu);
        return $file === null ? null : ['id_file' => $file['id_file'], 'filename' => $file['filename']];
    }

    /** @return array<int,int> */
    private function int_list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_numeric($item)) {
                $out[] = (int) $item;
            } elseif (is_array($item) && isset($item['id']) && is_numeric($item['id'])) {
                $out[] = (int) $item['id'];
            }
        }
        return array_values(array_unique($out));
    }
}
