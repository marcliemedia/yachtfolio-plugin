<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Domain;

/**
 * One yacht, normalised from the brochure payload plus (for owned yachts) the
 * bulk record. Every field is optional on the API side, so every property here
 * has a defined empty shape instead of being absent.
 */
final class YachtPayload
{
    public function __construct(
        public readonly int $yf_id,
        public readonly string $name,
        public readonly string $registry_port,
        /** @var array<string,mixed> flat snake_case spec */
        public readonly array $spec,
        public readonly string $description,
        /** @var array<int,string> */
        public readonly array $key_features,
        /** @var array<int,array<string,mixed>> */
        public readonly array $crew,
        /** @var array<string,mixed> */
        public readonly array $video,
        /** @var array<string,array<int,array{id_file:string,filename:string,title:?string,id_order:?int}>> */
        public readonly array $galleries,
        /** @var array<int,array{season_id:int,currency:string,min_rate:?float,max_rate:?float,term:string}> */
        public readonly array $rates,
        /** @var array<int,array<int,int>> season id => area ids */
        public readonly array $areas_by_season,
        /** @var array<int,int> */
        public readonly array $equipment,
        /** @var array<int,array<int,int>> season id => request ids */
        public readonly array $special_requests,
        /** @var array<int,array{licence_id:int,status_id:int,season_id:int,date_added:string}> */
        public readonly array $licences,
        /** @var array<int,int> */
        public readonly array $seasons_unavailable,
        /** @var array<string,string> */
        public readonly array $broker,
        /** @var array{id_file:string,filename:string}|null */
        public readonly ?array $sample_menu,
        public readonly bool $owned,
        public readonly string $last_modified,
        /** @var array{brochure:array<string,mixed>,bulk:array<string,mixed>|null} */
        public readonly array $raw,
    ) {
    }

    public function spec(string $key, mixed $default = null): mixed
    {
        $value = $this->spec[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        return $value;
    }

    public function spec_string(string $key, string $default = ''): string
    {
        $value = $this->spec($key);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function spec_int(string $key, ?int $default = null): ?int
    {
        $value = $this->spec($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function spec_float(string $key, ?float $default = null): ?float
    {
        $value = $this->spec($key);
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value) && $value !== '') {
            $clean = str_replace(',', '.', $value);
            if (is_numeric($clean)) {
                return (float) $clean;
            }
        }
        return $default;
    }

    /** @return array<int,array{id_file:string,filename:string,title:?string,id_order:?int}> */
    public function gallery(string $bucket): array
    {
        return $this->galleries[$bucket] ?? [];
    }

    public function has_prices(): bool
    {
        return $this->rates !== [];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'yf_id'               => $this->yf_id,
            'name'                => $this->name,
            'registry_port'       => $this->registry_port,
            'spec'                => $this->spec,
            'description'         => $this->description,
            'key_features'        => $this->key_features,
            'crew'                => $this->crew,
            'video'               => $this->video,
            'galleries'           => $this->galleries,
            'rates'               => $this->rates,
            'areas_by_season'     => $this->areas_by_season,
            'equipment'           => $this->equipment,
            'special_requests'    => $this->special_requests,
            'licences'            => $this->licences,
            'seasons_unavailable' => $this->seasons_unavailable,
            'broker'              => $this->broker,
            'sample_menu'         => $this->sample_menu,
            'owned'               => $this->owned,
            'last_modified'       => $this->last_modified,
            'raw'                 => $this->raw,
        ];
    }
}
