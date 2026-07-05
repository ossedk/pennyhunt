<?php

namespace App\Services\Features;

/**
 * Pure technical features computed from a ticker's own daily bars, as-of the
 * signal day (index into the ascending bar array). Shared by the Backtester
 * and the live SignalEngine so research and production can never drift.
 *
 *  - rvol: signal-day volume / trailing-20-session average volume — the
 *    relative-volume number momentum traders actually watch.
 *  - atr_pct: 14-session average true range as a fraction of the close —
 *    the stock's normal daily range.
 *  - range_expansion: signal-day true range / ATR — is today's bar unusually
 *    wide (breakout behavior) or business as usual?
 *  - dist_52w_high: close / trailing-252-session high − 1 (≤ 0). Near zero =
 *    breaking out to new highs; −0.8 = broken chart 80% off highs.
 *  - up_streak: consecutive up-closes ending at the signal day (capped 10).
 *  - gap_open: signal-day open / prior close − 1 — overnight repricing
 *    (news, PR) that intraday flow then confirms or fades.
 *
 * All null-tolerant: features needing more history than the array holds
 * return null rather than a fabricated value.
 */
class TechnicalFeatures
{
    public const FEATURE_KEYS = [
        'rvol', 'atr_pct', 'range_expansion', 'dist_52w_high', 'up_streak', 'gap_open',
    ];

    /**
     * @param  array<int, array{date: string, open: float, high: float, low: float, close: float, volume: float}>  $bars  ascending by date
     * @param  int  $sigIdx  index of the signal-day bar within $bars
     * @return array{rvol: ?float, atr_pct: ?float, range_expansion: ?float, dist_52w_high: ?float, up_streak: ?int, gap_open: ?float}
     */
    public static function compute(array $bars, int $sigIdx): array
    {
        if (! isset($bars[$sigIdx])) {
            return array_fill_keys(self::FEATURE_KEYS, null);
        }

        $bar = $bars[$sigIdx];

        return [
            'rvol' => self::rvol($bars, $sigIdx),
            'atr_pct' => self::atrPct($bars, $sigIdx),
            'range_expansion' => self::rangeExpansion($bars, $sigIdx),
            'dist_52w_high' => self::dist52wHigh($bars, $sigIdx),
            'up_streak' => self::upStreak($bars, $sigIdx),
            'gap_open' => $sigIdx >= 1 && $bars[$sigIdx - 1]['close'] > 0
                ? round($bar['open'] / $bars[$sigIdx - 1]['close'] - 1, 4)
                : null,
        ];
    }

    protected static function rvol(array $bars, int $sigIdx): ?float
    {
        $trailing = array_column(array_slice($bars, max(0, $sigIdx - 20), min(20, $sigIdx)), 'volume');

        if (count($trailing) < 10) {
            return null;
        }

        $avg = array_sum($trailing) / count($trailing);

        return $avg > 0 ? round($bars[$sigIdx]['volume'] / $avg, 2) : null;
    }

    /** True range of bar $i (needs the prior close for gap handling). */
    protected static function trueRange(array $bars, int $i): float
    {
        $high = $bars[$i]['high'];
        $low = $bars[$i]['low'];
        $prevClose = $i >= 1 ? $bars[$i - 1]['close'] : $bars[$i]['open'];

        return max($high - $low, abs($high - $prevClose), abs($low - $prevClose));
    }

    protected static function atrPct(array $bars, int $sigIdx): ?float
    {
        if ($sigIdx < 14 || $bars[$sigIdx]['close'] <= 0) {
            return null;
        }

        $sum = 0.0;

        for ($i = $sigIdx - 13; $i <= $sigIdx; $i++) {
            $sum += self::trueRange($bars, $i);
        }

        return round(($sum / 14) / $bars[$sigIdx]['close'], 4);
    }

    protected static function rangeExpansion(array $bars, int $sigIdx): ?float
    {
        $atrPct = self::atrPct($bars, $sigIdx);

        if ($atrPct === null || $atrPct <= 0 || $bars[$sigIdx]['close'] <= 0) {
            return null;
        }

        $atr = $atrPct * $bars[$sigIdx]['close'];

        return round(self::trueRange($bars, $sigIdx) / $atr, 2);
    }

    protected static function dist52wHigh(array $bars, int $sigIdx): ?float
    {
        // Accept a shorter window (min ~3 months listed) — the high of the
        // available history is what traders chart anyway.
        $window = array_slice($bars, max(0, $sigIdx - 251), min(252, $sigIdx + 1));

        if (count($window) < 60 || $bars[$sigIdx]['close'] <= 0) {
            return null;
        }

        $high = max(array_column($window, 'high'));

        return $high > 0 ? round($bars[$sigIdx]['close'] / $high - 1, 4) : null;
    }

    protected static function upStreak(array $bars, int $sigIdx): ?int
    {
        if ($sigIdx < 1) {
            return null;
        }

        $streak = 0;

        for ($i = $sigIdx; $i >= 1 && $streak < 10; $i--) {
            if ($bars[$i]['close'] > $bars[$i - 1]['close']) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }
}
