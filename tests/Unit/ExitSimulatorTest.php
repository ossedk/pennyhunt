<?php

namespace Tests\Unit;

use App\Services\Backtesting\ExitSimulator;
use PHPUnit\Framework\TestCase;

class ExitSimulatorTest extends TestCase
{
    protected ExitSimulator $sim;

    protected function setUp(): void
    {
        $this->sim = new ExitSimulator;
    }

    /** @return array<int, array{date: string, open: float, high: float, low: float, close: float}> */
    protected function bars(array $rows): array
    {
        return array_map(fn (array $r, int $i): array => [
            'date' => gmdate('Y-m-d', strtotime('2025-01-06 UTC') + $i * 86400),
            'open' => $r[0], 'high' => $r[1], 'low' => $r[2], 'close' => $r[3],
        ], $rows, array_keys($rows));
    }

    public function test_legacy_config_reproduces_fixed_stop_and_time_exit(): void
    {
        // Dips to 0.89 on day 1 → 10% stop at 0.90 fills at the stop.
        $bars = $this->bars([
            [1.00, 1.05, 0.98, 1.02],
            [1.00, 1.01, 0.89, 0.95],
        ]);

        $result = $this->sim->simulate(['stop_loss' => 0.10, 'max_hold' => 5], 1.00, 0.95, 0.08, $bars);

        $this->assertSame('stop', $result['reason']);
        $this->assertEqualsWithDelta(-0.10, $result['return'], 0.001);

        // No stop touched → time exit at day-5 close.
        $flat = $this->bars(array_fill(0, 6, [1.00, 1.04, 0.96, 1.01]));
        $result = $this->sim->simulate(['stop_loss' => 0.10, 'max_hold' => 5], 1.00, 0.95, 0.08, $flat);

        $this->assertSame('time', $result['reason']);
        $this->assertSame(5, $result['day']);
        $this->assertEqualsWithDelta(0.01, $result['return'], 0.001);
    }

    public function test_atr_stop_survives_noise_a_fixed_stop_dies_on(): void
    {
        // 12% ATR stock wiggling -11% intraday then running: fixed 10% stops out,
        // 2x-ATR (24%) survives to the runner.
        $bars = $this->bars([
            [1.00, 1.05, 0.89, 1.00],
            [1.00, 1.30, 0.98, 1.28],
            [1.28, 1.60, 1.25, 1.55],
            [1.55, 1.60, 1.45, 1.50],
            [1.50, 1.55, 1.40, 1.45],
            [1.45, 1.50, 1.38, 1.42],
        ]);

        $fixed = $this->sim->simulate(['stop_loss' => 0.10, 'max_hold' => 5], 1.00, 0.95, 0.12, $bars);
        $atr = $this->sim->simulate(['atr_stop_mult' => 2.0, 'max_hold' => 5], 1.00, 0.95, 0.12, $bars);

        $this->assertSame('stop', $fixed['reason']);
        $this->assertSame('time', $atr['reason']);
        $this->assertEqualsWithDelta(0.42, $atr['return'], 0.001);
    }

    public function test_partial_take_blends_half_realized_half_trailed(): void
    {
        // Day 1 tags +30% (partial fills at 1.30), then fades to trail exit.
        $bars = $this->bars([
            [1.00, 1.05, 0.98, 1.03],
            [1.10, 1.45, 1.08, 1.40],
            [1.38, 1.42, 1.10, 1.12],
        ]);

        $result = $this->sim->simulate(
            ['atr_stop_mult' => 2.0, 'partial_take_at' => 0.30, 'trail_atr_mult' => 2.5, 'max_hold' => 10],
            1.00, 0.95, 0.10, $bars,
        );

        // Half at +30%; remainder trail-exits at 1.12 close (peak 1.40 − 2.5×0.10 = 1.15 breached).
        $this->assertSame('trail', $result['reason']);
        $this->assertEqualsWithDelta(0.5 * 0.30 + 0.5 * 0.12, $result['return'], 0.005);
    }

    public function test_breakeven_stop_after_partial_protects_the_remainder(): void
    {
        // Partial at +30% then a crash through entry: remainder exits at breakeven.
        $bars = $this->bars([
            [1.00, 1.35, 0.99, 1.20],
            [1.10, 1.12, 0.70, 0.75],
        ]);

        $result = $this->sim->simulate(
            ['atr_stop_mult' => 2.0, 'partial_take_at' => 0.30, 'max_hold' => 10],
            1.00, 0.95, 0.10, $bars,
        );

        $this->assertSame('stop', $result['reason']);
        // Half at +30%, half at breakeven (0%) → +15%.
        $this->assertEqualsWithDelta(0.15, $result['return'], 0.005);
    }

    public function test_mention_collapse_exits_when_the_crowd_leaves(): void
    {
        $bars = $this->bars(array_fill(0, 8, [1.00, 1.06, 0.97, 1.02]));

        // Fire day 40 mentions; day offsets 0,1 have 30, then 2 (< 25% of 40).
        $mentions = [-1 => 40, 0 => 30, 1 => 2, 2 => 1, 3 => 0];

        $result = $this->sim->simulate(
            ['atr_stop_mult' => 2.0, 'mention_collapse_frac' => 0.25, 'max_hold' => 10],
            1.00, 0.95, 0.10, $bars, $mentions,
        );

        $this->assertSame('mention_collapse', $result['reason']);
        $this->assertSame(2, $result['day']);
    }

    public function test_gap_veto_skips_trades_that_gap_over_the_cap(): void
    {
        $bars = $this->bars([[1.30, 1.40, 1.25, 1.35]]);

        // Signal close 1.00, entry open 1.30 = +30% gap > 15% cap.
        $result = $this->sim->simulate(['max_entry_gap' => 0.15, 'max_hold' => 5], 1.30, 1.00, 0.10, $bars);

        $this->assertTrue($result['skipped']);
        $this->assertSame('gap_veto', $result['reason']);
    }
}
