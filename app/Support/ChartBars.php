<?php

namespace App\Support;

/**
 * Intraday chart payloads. Lightweight-charts renders UTCTimestamps
 * literally (no timezone localization), so we shift epochs to Eastern
 * wall-clock time server-side — the axis then reads 09:30, 16:00 etc.
 * exactly as a US trader expects. Offsets are computed per-bar, so DST
 * transitions inside a window stay correct.
 */
class ChartBars
{
    /** Shift a UTC epoch (seconds) to an ET wall-clock epoch. */
    public static function toEastern(int $utcSeconds): int
    {
        static $tz = null;
        $tz ??= new \DateTimeZone('America/New_York');

        return $utcSeconds + $tz->getOffset(new \DateTimeImmutable('@'.$utcSeconds));
    }

    /**
     * Polygon aggregate rows → chart bars keyed by ET wall-clock time.
     *
     * @param  array<int, array{t: int, o: float, h: float, l: float, c: float, v: float}>  $aggregates
     * @return array<int, array{time: int, date: string, open: float, high: float, low: float, close: float, volume: float}>
     */
    public static function fromPolygon(array $aggregates): array
    {
        return array_map(function (array $bar): array {
            $time = self::toEastern(intdiv($bar['t'], 1000));

            return [
                'time' => $time,
                'date' => gmdate('Y-m-d', $time),
                'open' => $bar['o'],
                'high' => $bar['h'],
                'low' => $bar['l'],
                'close' => $bar['c'],
                'volume' => $bar['v'],
            ];
        }, $aggregates);
    }
}
