<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Mapping;

use Otium\Yachtfolio\Reference\SeasonResolver;
use Otium\Yachtfolio\Support\Settings;

/**
 * Rates: the feed's rows are stored as they come (`yf_rates`); the High / Low
 * boxes are pre-filled from them.
 *
 * high = summer season max_rate
 * low  = summer season min_rate
 *
 * "Summer" is the earliest summer season that has not ended (else the latest
 * one, until next summer is priced), because High and
 * Low are the Mediterranean summer prices the site sells. Taking whatever
 * season was current filled both boxes with the winter rate from October to
 * April (AD Astra, client e-mail 2026-11-05).
 *
 * Nothing else is derived. Yachtfolio has no shoulder rate and no high/low
 * date windows: filling Shoulder from the next season and the time boxes from
 * the whole season window invented data the client then could not remove
 * (client e-mail 2026-10-23). Those boxes, and the Summer / Winter rate boxes,
 * are the editors' alone; the page never prints the feed's season rows.
 *
 * A null amount renders as an empty string, never "0 €", and the currency is
 * always taken from the row — both EUR and USD occur in the live feed.
 */
final class RatesTable
{
    /**
     * @param array<int,array{season_id:int,currency:string,min_rate:?float,max_rate:?float,term:string}> $rates
     * @return array{high:string,low:string}
     */
    public static function display(array $rates, SeasonResolver $seasons, Settings $settings, ?int $asOf = null): array
    {
        $out = ['high' => '', 'low' => ''];
        if ($rates === []) {
            return $out;
        }

        $row = self::summer_row($rates, $seasons, gmdate('Y-m-d', $asOf ?? time()));
        if ($row === null) {
            // No summer price in the feed at all (a winter-only yacht): the
            // current season, else the earliest season the feed priced.
            $current = $seasons->current($asOf);
            $row = self::row_for($rates, $current !== null ? (int) $current['id'] : 0) ?? $rates[0];
        }

        $locale = (string) $settings->get('currency_locale', 'hr');
        $out['high'] = Transforms::money($row['max_rate'], $row['currency'], $locale);
        $out['low']  = Transforms::money($row['min_rate'], $row['currency'], $locale);
        return $out;
    }

    /**
     * The summer season row the High / Low boxes describe: the earliest one
     * that has not ended on $date, else — next summer not priced yet — the
     * latest one that has. Last summer's prices stand for summer far better
     * than a winter rate does. A season the reference list does not know has
     * no dates and is skipped.
     *
     * @param array<int,array{season_id:int,currency:string,min_rate:?float,max_rate:?float,term:string}> $rates
     * @return array{season_id:int,currency:string,min_rate:?float,max_rate:?float,term:string}|null
     */
    private static function summer_row(array $rates, SeasonResolver $seasons, string $date): ?array
    {
        $next = null;
        $nextStart = '';
        $last = null;
        $lastStart = '';
        foreach ($rates as $rate) {
            $seasonId = (int) $rate['season_id'];
            if ($seasons->kind($seasonId) !== 'summer') {
                continue;
            }
            $season = $seasons->by_id($seasonId);
            $start = (string) ($season['season_start'] ?? '');
            $end = (string) ($season['season_end'] ?? '');
            if ($start === '' || $end === '') {
                continue;
            }
            if ($end >= $date) {
                if ($next === null || $start < $nextStart) {
                    $next = $rate;
                    $nextStart = $start;
                }
            } elseif ($last === null || $start > $lastStart) {
                $last = $rate;
                $lastStart = $start;
            }
        }
        return $next ?? $last;
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
}
