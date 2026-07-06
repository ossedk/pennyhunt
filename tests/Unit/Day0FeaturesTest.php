<?php

namespace Tests\Unit;

use App\Services\Features\Day0Features;
use PHPUnit\Framework\TestCase;

class Day0FeaturesTest extends TestCase
{
    /** Minute bars for 2025-06-10 starting 9:30 ET (13:30 UTC, EDT). */
    protected function minuteBars(array $rows): array
    {
        $base = strtotime('2025-06-10 13:30:00 UTC') * 1000;

        return array_map(fn (array $r, int $i): array => [
            't' => $base + $i * 60_000,
            'o' => $r[0], 'h' => $r[1], 'l' => $r[2], 'c' => $r[3], 'v' => $r[4],
        ], $rows, array_keys($rows));
    }

    public function test_opening_range_breakout_reads_positive(): void
    {
        // 30 one-minute bars grinding from 1.00 to 1.20 on steady volume.
        $rows = [];

        for ($i = 0; $i < 30; $i++) {
            $p = 1.00 + $i * 0.0067;
            $rows[] = [$p, $p + 0.005, $p - 0.005, $p + 0.003, 50_000];
        }

        $f = Day0Features::compute($this->minuteBars($rows), 0.95, 3_000_000);

        $this->assertGreaterThan(0.15, $f['or_return_30m']);
        $this->assertGreaterThan(0, $f['vwap_dist_30m']); // rising tape holds above VWAP
        $this->assertEqualsWithDelta(0.5, $f['or_vol_share'], 0.01); // 1.5M of 3M avg
        $this->assertFalse($f['gap_faded']); // opened above prev close, never lost it
    }

    public function test_gap_fade_is_flagged(): void
    {
        // Opens +15% over prev close 1.00, immediately sells off through it.
        $rows = [];

        for ($i = 0; $i < 30; $i++) {
            $p = 1.15 - $i * 0.008;
            $rows[] = [$p, $p + 0.004, $p - 0.006, $p - 0.004, 40_000];
        }

        $f = Day0Features::compute($this->minuteBars($rows), 1.00, 2_000_000);

        $this->assertTrue($f['gap_faded']);
        $this->assertLessThan(0, $f['or_return_30m']);
        $this->assertLessThan(0, $f['vwap_dist_30m']); // falling tape sits below VWAP
    }

    public function test_thin_or_missing_data_returns_nulls(): void
    {
        $f = Day0Features::compute([], 1.00, 1_000_000);
        $this->assertNull($f['or_return_30m']);

        // Only 3 minute bars — too thin to read.
        $thin = $this->minuteBars([[1, 1.01, 0.99, 1, 100], [1, 1.01, 0.99, 1, 100], [1, 1.01, 0.99, 1, 100]]);
        $this->assertNull(Day0Features::compute($thin, 1.00, 1_000_000)['or_return_30m']);
    }
}
