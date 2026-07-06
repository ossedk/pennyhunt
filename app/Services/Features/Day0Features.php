<?php

namespace App\Services\Features;

/**
 * Day-0 microstructure: what the first 30 regular-session minutes of the
 * signal day looked like. This is where small-cap momentum is decided —
 * an opening range that holds VWAP on real volume continues; a gap that
 * fades below the prior close is distribution.
 *
 *  - or_return_30m: 10:00 ET price vs 9:30 open.
 *  - vwap_dist_30m: 10:00 ET price vs session VWAP so far (above = holding).
 *  - or_vol_share: first-30m volume as a fraction of the trailing average
 *    FULL-DAY volume — 0.5 means half a normal day traded in 30 minutes.
 *  - gap_faded: opened above the prior close but traded below it within
 *    the first 30 minutes (the gap failed).
 *
 * As-of correct by construction: uses only the signal session's first 30
 * minutes, and entries happen at the NEXT session's open.
 */
class Day0Features
{
    public const FEATURE_KEYS = ['or_return_30m', 'vwap_dist_30m', 'or_vol_share', 'gap_faded'];

    /**
     * @param  array<int, array{t: int, o: float, h: float, l: float, c: float, v: float}>  $minuteBars  the signal day's minute bars (ms epoch, asc)
     * @param  float|null  $prevClose  prior session close
     * @param  float|null  $avgDailyVolume  trailing average full-day volume
     * @return array{or_return_30m: ?float, vwap_dist_30m: ?float, or_vol_share: ?float, gap_faded: ?bool}
     */
    public static function compute(array $minuteBars, ?float $prevClose, ?float $avgDailyVolume): array
    {
        $empty = array_fill_keys(self::FEATURE_KEYS, null);

        if ($minuteBars === []) {
            return $empty;
        }

        // Regular session open in ET for the bar date (handles DST via tz).
        $barDate = gmdate('Y-m-d', intdiv($minuteBars[0]['t'], 1000));
        $openTs = (new \DateTimeImmutable($barDate.' 09:30:00', new \DateTimeZone('America/New_York')))->getTimestamp() * 1000;
        $cutTs = $openTs + 30 * 60 * 1000;

        $window = array_values(array_filter(
            $minuteBars,
            fn (array $bar): bool => $bar['t'] >= $openTs && $bar['t'] < $cutTs,
        ));

        if (count($window) < 5) {
            return $empty; // too thin to read (illiquid or partial data)
        }

        $open = $window[0]['o'];
        $last = end($window)['c'];

        if ($open <= 0) {
            return $empty;
        }

        $volume = 0.0;
        $pv = 0.0;
        $low = INF;

        foreach ($window as $bar) {
            $volume += $bar['v'];
            $pv += $bar['v'] * ($bar['h'] + $bar['l'] + $bar['c']) / 3;
            $low = min($low, $bar['l']);
        }

        $vwap = $volume > 0 ? $pv / $volume : null;

        return [
            'or_return_30m' => round($last / $open - 1, 4),
            'vwap_dist_30m' => $vwap !== null && $vwap > 0 ? round($last / $vwap - 1, 4) : null,
            'or_vol_share' => $avgDailyVolume !== null && $avgDailyVolume > 0
                ? round($volume / $avgDailyVolume, 4)
                : null,
            'gap_faded' => $prevClose !== null && $prevClose > 0
                ? ($open > $prevClose * 1.02 && $low < $prevClose)
                : null,
        ];
    }
}
