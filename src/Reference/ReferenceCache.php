<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Reference;

use Otium\Yachtfolio\Api\ApiError;
use Otium\Yachtfolio\Api\Client;
use Otium\Yachtfolio\Support\Logger;
use Otium\Yachtfolio\Support\Settings;

/**
 * The six reference tables, cached in one option.
 *
 * Live sizes: seasons 34, equipments 10, operating_areas_new 26 groups /
 * 107 areas, special_requests 5, licences_registrations 8, statuses 3.
 */
final class ReferenceCache
{
    public const OPTION = 'oy_yf_reference_cache';

    /** @var array{updated_at:int,tables:array<string,mixed>}|null */
    private ?array $cache = null;

    public function __construct(
        private Client $client,
        private Settings $settings,
        private Logger $log
    ) {
    }

    /**
     * @return array<string,mixed> counts per table, plus 'errors' when a table failed
     */
    public function refresh(bool $force = false): array
    {
        if (!$force && !$this->is_stale()) {
            return $this->counts();
        }

        $state = $this->state();
        $counts = [];
        $errors = [];

        foreach (Client::REFERENCE_TYPES as $type) {
            try {
                $rows = $this->client->reference($type);
                $state['tables'][$type] = $rows;
                $state['fetched'][$type] = time();
                $counts[$type] = $this->size($rows);
            } catch (ApiError $e) {
                // Keep the previous copy: stale reference data beats none.
                $errors[$type] = $e->kind . ': ' . $e->getMessage();
                $counts[$type] = $this->size($state['tables'][$type] ?? []);
                $this->log->warn('list', "reference table $type failed", ['error' => $e->getMessage()]);
            }
        }

        $state['updated_at'] = time();
        $this->cache = $state;
        update_option(self::OPTION, $state, false);

        if ($errors !== []) {
            $counts['errors'] = $errors;
        }

        return $counts;
    }

    public function is_stale(): bool
    {
        $state = $this->state();
        if ($state['tables'] === []) {
            return true;
        }
        $ttl = max(300, $this->settings->int('reference_ttl', 86400));
        return (time() - (int) $state['updated_at']) > $ttl;
    }

    /** @return array<int,array<string,mixed>> */
    public function seasons(): array
    {
        return $this->rows('seasons');
    }

    /** @return array<int,array<string,mixed>> */
    public function equipments(): array
    {
        return $this->rows('equipments');
    }

    /** @return array{groups:array<int,array<string,mixed>>,areas:array<int,array<string,mixed>>} */
    public function areas(): array
    {
        $table = $this->table('operating_areas_new');
        return [
            'groups' => is_array($table['groups'] ?? null) ? array_values($table['groups']) : [],
            'areas'  => is_array($table['areas'] ?? null) ? array_values($table['areas']) : [],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function special_requests(): array
    {
        return $this->rows('special_requests');
    }

    /** @return array<int,array<string,mixed>> */
    public function licences(): array
    {
        return $this->rows('licences_registrations');
    }

    /** @return array<int,array<string,mixed>> */
    public function licence_statuses(): array
    {
        return $this->rows('licences_registrations_statuses');
    }

    /** @return array<int,string> equipment id => name */
    public function equipment_names(): array
    {
        $out = [];
        foreach ($this->equipments() as $row) {
            if (isset($row['id'])) {
                $out[(int) $row['id']] = (string) ($row['name'] ?? '');
            }
        }
        return $out;
    }

    /** @return array<int,string> */
    public function special_request_names(): array
    {
        return $this->id_text_map($this->special_requests());
    }

    /** @return array<int,string> */
    public function licence_names(): array
    {
        return $this->id_text_map($this->licences());
    }

    /** @return array<int,string> */
    public function licence_status_names(): array
    {
        return $this->id_text_map($this->licence_statuses());
    }

    public function updated_at(): int
    {
        return (int) $this->state()['updated_at'];
    }

    /** @return array<string,int> table => seconds since fetch (-1 when never fetched) */
    public function ages(): array
    {
        $state = $this->state();
        $out = [];
        foreach (Client::REFERENCE_TYPES as $type) {
            $fetched = (int) ($state['fetched'][$type] ?? 0);
            if ($fetched <= 0) {
                $fetched = isset($state['tables'][$type]) ? (int) $state['updated_at'] : 0;
            }
            $out[$type] = $fetched > 0 ? max(0, time() - $fetched) : -1;
        }
        return $out;
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $out = [];
        foreach (Client::REFERENCE_TYPES as $type) {
            $out[$type] = $this->size($this->table($type));
        }
        return $out;
    }

    public function clear(): void
    {
        $this->cache = null;
        delete_option(self::OPTION);
    }

    /* ------------------------------------------------------------------ */

    /** @return array{updated_at:int,tables:array<string,mixed>,fetched:array<string,int>} */
    private function state(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION);
            $this->cache = [
                'updated_at' => is_array($stored) ? (int) ($stored['updated_at'] ?? 0) : 0,
                'tables'     => is_array($stored) && is_array($stored['tables'] ?? null) ? $stored['tables'] : [],
                'fetched'    => is_array($stored) && is_array($stored['fetched'] ?? null) ? $stored['fetched'] : [],
            ];
        }
        /** @var array{updated_at:int,tables:array<string,mixed>,fetched:array<string,int>} */
        return $this->cache;
    }

    /** @return array<int|string,mixed> */
    private function table(string $type): array
    {
        $state = $this->state();
        if (!isset($state['tables'][$type])) {
            // Lazy first fill so a mapper call never runs against nothing.
            $this->refresh(true);
            $state = $this->state();
        }
        $table = $state['tables'][$type] ?? [];
        return is_array($table) ? $table : [];
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(string $type): array
    {
        $table = $this->table($type);
        $rows = [];
        foreach ($table as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,string>
     */
    private function id_text_map(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (isset($row['id'])) {
                $out[(int) $row['id']] = (string) ($row['text'] ?? $row['name'] ?? '');
            }
        }
        return $out;
    }

    private function size(mixed $table): int
    {
        if (!is_array($table)) {
            return 0;
        }
        if (isset($table['areas']) && is_array($table['areas'])) {
            return count($table['areas']);
        }
        return count($table);
    }
}
