<?php

namespace Tests\Unit;

use App\Services\Features\TechnicalFeatures;
use PHPUnit\Framework\TestCase;

class TechnicalFeaturesTest extends TestCase
{
    /** @return array<int, array{date: string, open: float, high: float, low: float, close: float, volume: float}> */
    protected function flatBars(int $n, float $price = 1.0, float $volume = 100000.0): array
    {
        $bars = [];

        for ($i = 0; $i < $n; $i++) {
            $bars[] = [
                'date' => gmdate('Y-m-d', strtotime('2025-01-01 UTC') + $i * 86400),
                'open' => $price,
                'high' => $price * 1.02,
                'low' => $price * 0.98,
                'close' => $price,
                'volume' => $volume,
            ];
        }

        return $bars;
    }

    public function test_rvol_measures_volume_vs_trailing_average(): void
    {
        $bars = $this->flatBars(30);
        $bars[29]['volume'] = 500000.0; // 5x the trailing 100k average

        $f = TechnicalFeatures::compute($bars, 29);

        $this->assertEqualsWithDelta(5.0, $f['rvol'], 0.01);
    }

    public function test_dist_52w_high_is_zero_at_the_high_and_negative_below(): void
    {
        $bars = $this->flatBars(120);
        $bars[60]['high'] = 4.0; // the peak
        $bars[119]['close'] = 1.0;

        $f = TechnicalFeatures::compute($bars, 119);

        $this->assertEqualsWithDelta(-0.75, $f['dist_52w_high'], 0.01);

        // Close at the running high → 0 (allowing the intraday-high margin).
        $bars[119]['close'] = 4.0;
        $this->assertEqualsWithDelta(0.0, TechnicalFeatures::compute($bars, 119)['dist_52w_high'], 0.01);
    }

    public function test_up_streak_counts_consecutive_up_closes(): void
    {
        $bars = $this->flatBars(30);

        // Last 3 closes strictly rising, the one before falls.
        $bars[26]['close'] = 0.9;
        $bars[27]['close'] = 1.0;
        $bars[28]['close'] = 1.1;
        $bars[29]['close'] = 1.2;

        $this->assertSame(3, TechnicalFeatures::compute($bars, 29)['up_streak']);
    }

    public function test_gap_open_measures_overnight_repricing(): void
    {
        $bars = $this->flatBars(30);
        $bars[29]['open'] = 1.3; // +30% gap vs prior close of 1.0

        $this->assertEqualsWithDelta(0.30, TechnicalFeatures::compute($bars, 29)['gap_open'], 0.001);
    }

    public function test_range_expansion_flags_unusually_wide_bars(): void
    {
        $bars = $this->flatBars(30);
        // Normal bars have 4% range; make the signal bar a 20% range.
        $bars[29]['high'] = 1.12;
        $bars[29]['low'] = 0.92;

        $f = TechnicalFeatures::compute($bars, 29);

        $this->assertGreaterThan(2.0, $f['range_expansion']);
    }

    public function test_insufficient_history_returns_nulls_not_fabrications(): void
    {
        $bars = $this->flatBars(5);

        $f = TechnicalFeatures::compute($bars, 4);

        $this->assertNull($f['rvol']);
        $this->assertNull($f['atr_pct']);
        $this->assertNull($f['dist_52w_high']);
    }
}
