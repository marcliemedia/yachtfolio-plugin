<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Mapping;

use Otium\Yachtfolio\Reference\SeasonResolver;
use Otium\Yachtfolio\Support\Settings;

/**
 * Rates: the feed's rows are authoritative, the three legacy display periods
 * are derived from them (decision D2).
 *
 * high     = current season max_rate
 * low      = current season min_rate
 * shoulder = next season min_rate (falls back to that season's max_rate)
 *
 * A null amount renders as an empty string, never "0 €", and the currency is
 * always taken from the row — both EUR and USD occur in the live feed.
 */
final class RatesTable
{
    /**
     * @param array<int,array{season_id:int,currency:string,min_rate:?float,max_rate:?float,term:string}> $rates
     * @return array{high:array{rate:string,time:string,season_id:int},low:array{rate:string,time:string,season_id:int},shoulder:array{rate:string,time:string,season_id:int}}
     */
    public static function display(array $rates, SeasonResolver $seasons, Settings $settings): array
    {
        $empty = ['rate' => '', 'time' => '', 'season_id' => 0];
        $out = ['high' => $empty, 'low' => $empty, 'shoulder' => $empty];

        if ($rates === []) {
            return $out;
        }

        $locale = (string) $settings->get('currency_locale', 'hr');
        $current = $seasons->current();
        $next = $seasons->next();

        $currentId = $current !== null ? (int) $current['id'] : 0;
        $nextId = $next !== null ? (int) $next['id'] : 0;

        $currentRow = self::row_for($rates, $currentId);
        $nextRow = self::row_for($rates, $nextId);

        // Fall back to the earliest season the feed actually priced, so a yacht
        // whose current season is unpriced still shows something truthful.
        if ($currentRow === null) {
            $currentRow = $rates[0];
            $currentId = (int) $currentRow['season_id'];
        }

        if ($currentRow !== null) {
            $out['high'] = [
                'rate'      => Transforms::money($currentRow['max_rate'], $currentRow['currency'], $locale),
                'time'      => $seasons->window_text($currentId),
                'season_id' => $currentId,
            ];
            $out['low'] = [
                'rate'      => Transforms::money($currentRow['min_rate'], $currentRow['currency'], $locale),
                'time'      => $seasons->window_text($currentId),
                'season_id' => $currentId,
            ];
        }

        if ($nextRow !== null) {
            $shoulderSource = (string) $settings->get('shoulder_source', 'next_season_min');
            $amount = $shoulderSource === 'next_season_max'
                ? ($nextRow['max_rate'] ?? $nextRow['min_rate'])
                : ($nextRow['min_rate'] ?? $nextRow['max_rate']);

            $out['shoulder'] = [
                'rate'      => Transforms::money($amount, $nextRow['currency'], $locale),
                'time'      => $seasons->window_text($nextId),
                'season_id' => $nextId,
            ];
        }

        return $out;
    }

    /**
     * Faithful copy of every priced row for the `yf_rates` repeater.
     *
     * @param array<int,array{season_id:int,currency:string,min_rate:?float,max_rate:?float,term:string}> $rates
     * @return array<int,array<string,string>>
     */
    public static function repeater(array $rates, SeasonResolver $seasons): array
    {
        $rows = [];
        foreach ($rates as $rate) {
            $seasonId = (int) $rate['season_id'];
            $rows[] = [
                'season_id'    => (string) $seasonId,
                'season_label' => $seasons->label($seasonId),
                'season_start' => (string) ($seasons->by_id($seasonId)['season_start'] ?? ''),
                'season_end'   => (string) ($seasons->by_id($seasonId)['season_end'] ?? ''),
                'currency'     => $rate['currency'],
                'min_rate'     => $rate['min_rate'] === null ? '' : (string) (int) round($rate['min_rate']),
                'max_rate'     => $rate['max_rate'] === null ? '' : (string) (int) round($rate['max_rate']),
                'term'         => $rate['term'],
            ];
        }

        usort($rows, static fn(array $a, array $b): int => (int) $a['season_id'] <=> (int) $b['season_id']);

        return $rows;
    }

    /**
     * @param array<int,array{season_id:int,currency:string,min_rate:?float,max_rate:?float,term:string}> $rates
     * @return array{season_id:int,currency:string,min_rate:?float,max_rate:?float,term:string}|null
     */
    private static function row_for(array $rates, int $seasonId): ?array
    {
        if ($seasonId <= 0) {
            return null;
        }
        foreach ($rates as $rate) {
            if ((int) $rate['season_id'] === $seasonId) {
                return $rate;
            }
        }
        return null;
    }

    /**
     * The rate rows a customer may still book, oldest first.
     *
     * The feed keeps history: ACAPELLA carries 14 rows reaching back to
     * "Winter 2020/2021". Showing those on a charter page is wrong, so a row
     * survives only while its season has not ended. This is the single source
     * of truth for "how many rate rows are showable" — both the presence flag
     * `yf_has_rates` and the `{yf_rates_table}` renderer call it, so the badge
     * can never disagree with the table.
     *
     * Rows without a parseable `season_end` are kept: a missing date must not
     * silently delete a live price.
     *
     * @param array<int,array<string,mixed>> $rates
     * @return array<int,array<string,mixed>>
     */
    public static function upcoming(array $rates, ?string $today = null): array
    {
        $cutoff = $today ?? current_time('Y-m-d');

        $rows = array_values(array_filter($rates, static function ($row) use ($cutoff): bool {
            if (!is_array($row)) {
                return false;
            }
            $end = trim((string) ($row['season_end'] ?? ''));
            return $end === '' || $end >= $cutoff;
        }));

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($a['season_start'] ?? ''), (string) ($b['season_start'] ?? ''));
        });

        return $rows;
    }
}
