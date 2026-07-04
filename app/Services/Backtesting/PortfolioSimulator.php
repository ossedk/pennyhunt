<?php

namespace App\Services\Backtesting;

use App\Models\BacktestRun;

/**
 * Portfolio-level simulation over a run's fired events: instead of the
 * per-trade equal-weight PnL the summary reports, this replays trades
 * chronologically against an equity curve and compares position-sizing
 * strategies:
 *
 *  - equal:      fixed % of current equity per trade
 *  - kelly_half: fractional Kelly (0.5x) from the walk-forward confidence
 *  - kelly_full: full Kelly (research reference — nobody should trade this)
 *
 * Kelly fraction per trade: f* = p - (1 - p) / b on the NET-WIN outcome —
 * not on the >= +30% "hit" definition. With a stop in place a losing trade
 * costs ~13%, not 100%, so the economically meaningful split is net
 * profit vs net loss. p (win rate) and b (avg win / avg loss) are estimated
 * ONLY from trades already closed before entry, conditioned on the trade's
 * walk-forward confidence tercile — confidence ranks trades, realized
 * history prices them. Two hard caps keep it honest:
 *
 *  - max_position_pct of current equity per position, and
 *  - liquidity_pct of the signal-day dollar volume — a $40k order in a
 *    $2M/day name moves the market; the simulator refuses sizes it could
 *    not realistically fill.
 *
 * Positions are held at cost while open (daily bars carry no intraday mark)
 * and realize net-of-friction PnL on their simulated exit date. Only events
 * with a confidence score are traded, so all strategies compare on the same
 * trade set.
 */
class PortfolioSimulator
{
    protected const DEFAULTS = [
        'initial_equity' => 100_000.0,
        'equal_weight_pct' => 0.05,
        'max_position_pct' => 0.10,
        'liquidity_pct' => 0.01,
        'kelly_min_history' => 20, // closed trades before Kelly starts betting
        'kelly_min_bucket' => 10,  // per-tercile sample below which we widen to all history
    ];

    /** @var array<string, float|int> */
    protected array $options;

    /**
     * @param  array<string, float|int>  $options
     * @return array<string, mixed>
     */
    public function run(BacktestRun $run, array $options = []): array
    {
        $this->options = [...self::DEFAULTS, ...$options];

        $friction = (float) ($run->params['friction'] ?? 0.05);

        $trades = $run->events()
            ->where('fired', true)
            ->whereNotNull('confidence')
            ->whereNotNull('exit_return')
            ->whereNotNull('exit_date')
            ->orderBy('entry_date')
            ->orderByDesc('confidence')
            ->get()
            ->map(fn ($e): array => [
                'symbol' => $e->symbol,
                'entry_date' => $e->entry_date->toDateString(),
                'exit_date' => $e->exit_date->toDateString(),
                'net_return' => $e->exit_return - $friction,
                'confidence' => (float) $e->confidence,
                'dollar_volume' => $e->dollar_volume !== null ? (float) $e->dollar_volume : null,
            ])
            ->values()
            ->all();

        if (count($trades) < 5) {
            return ['error' => 'Not enough confidence-scored fired events to simulate (need >= 5, have '.count($trades).'). Run pennyhunt:train-confidence first.'];
        }

        $kellyStats = $this->asOfKellyStats($trades);
        $equalPct = (float) $this->options['equal_weight_pct'];

        $kellySizer = fn (float $fraction): callable => function (array $trade, float $equity, int $i) use ($kellyStats, $fraction, $equalPct): float {
            $stats = $kellyStats[$i];

            // Warm-up (not enough closed trades yet): bet the equal-weight
            // size so all strategies share the early path.
            if ($stats === null) {
                return $equity * $equalPct;
            }

            return $equity * $this->kellyFraction($stats['p'], $stats['b'], $fraction);
        };

        $strategies = [
            'equal' => fn (array $trade, float $equity, int $i): float => $equity * $equalPct,
            'kelly_half' => $kellySizer(0.5),
            'kelly_full' => $kellySizer(1.0),
        ];

        $results = [];
        $curves = [];

        foreach ($strategies as $name => $sizer) {
            $outcome = $this->simulate($trades, $sizer);
            $curves[$name] = $outcome['curve'];
            unset($outcome['curve']);
            $results[$name] = $outcome;
        }

        return [
            'trades' => count($trades),
            'friction' => $friction,
            'options' => $this->options,
            'strategies' => $results,
            'curves' => $this->mergeCurves($curves),
        ];
    }

    /**
     * Chronological replay: close positions whose exit date has passed, then
     * size and open the new trade from available cash.
     *
     * @param  array<int, array<string, mixed>>  $trades
     * @param  callable(array<string, mixed>, float, int): float  $sizer
     * @return array<string, mixed>
     */
    protected function simulate(array $trades, callable $sizer): array
    {
        $initial = (float) $this->options['initial_equity'];
        $maxPct = (float) $this->options['max_position_pct'];
        $liquidityPct = (float) $this->options['liquidity_pct'];

        $cash = $initial;
        $open = []; // ['exit_date' => .., 'cost' => .., 'net_return' => ..]
        $curve = [];
        $taken = 0;
        $skipped = 0;
        $liquidityCapped = 0;
        $positionPcts = [];

        $closeThrough = function (string $date) use (&$open, &$cash, &$curve): void {
            usort($open, fn ($a, $b) => $a['exit_date'] <=> $b['exit_date']);

            while ($open !== [] && $open[0]['exit_date'] <= $date) {
                $position = array_shift($open);
                $cash += $position['cost'] * (1 + $position['net_return']);
                $equity = $cash + array_sum(array_column($open, 'cost'));
                $curve[$position['exit_date']] = round($equity, 2);
            }
        };

        foreach ($trades as $i => $trade) {
            $closeThrough($trade['entry_date']);

            $equity = $cash + array_sum(array_column($open, 'cost'));
            $size = min($sizer($trade, $equity, $i), $equity * $maxPct, $cash);

            if ($trade['dollar_volume'] !== null && $size > $trade['dollar_volume'] * $liquidityPct) {
                $size = $trade['dollar_volume'] * $liquidityPct;
                $liquidityCapped++;
            }

            // Sub-0.5% positions are noise (Kelly said "don't bet" or cash ran out).
            if ($size < $equity * 0.005) {
                $skipped++;

                continue;
            }

            $cash -= $size;
            $open[] = [
                'exit_date' => $trade['exit_date'],
                'cost' => $size,
                'net_return' => $trade['net_return'],
            ];
            $taken++;
            $positionPcts[] = $size / $equity;
        }

        $closeThrough('9999-12-31');

        $final = $cash;
        $maxDrawdown = $this->maxDrawdown([$initial, ...array_values($curve)]);

        return [
            'final_equity' => round($final, 2),
            'total_return' => round($final / $initial - 1, 4),
            'max_drawdown' => $maxDrawdown,
            'trades_taken' => $taken,
            'trades_skipped' => $skipped,
            'liquidity_capped' => $liquidityCapped,
            'avg_position_pct' => $positionPcts === [] ? null : round(array_sum($positionPcts) / count($positionPcts), 4),
            'curve' => $curve,
        ];
    }

    protected function kellyFraction(float $p, float $payoff, float $fraction): float
    {
        $f = $p - (1 - $p) / $payoff;

        return max($f, 0.0) * $fraction;
    }

    /**
     * As-of Kelly inputs per trade: p = realized NET-win rate and b = avg
     * net win / avg net loss, estimated from trades that CLOSED before this
     * trade's entry (never the future), restricted to the trade's confidence
     * tercile when that bucket has enough samples. Null during warm-up.
     *
     * @param  array<int, array<string, mixed>>  $trades
     * @return array<int, array{p: float, b: float}|null>
     */
    protected function asOfKellyStats(array $trades): array
    {
        $minHistory = (int) $this->options['kelly_min_history'];
        $minBucket = (int) $this->options['kelly_min_bucket'];

        $closed = $trades;
        usort($closed, fn ($a, $b) => $a['exit_date'] <=> $b['exit_date']);

        $stats = [];

        foreach ($trades as $i => $trade) {
            $history = [];

            foreach ($closed as $prior) {
                if ($prior['exit_date'] > $trade['entry_date']) {
                    break;
                }

                $history[] = $prior;
            }

            if (count($history) < $minHistory) {
                $stats[$i] = null;

                continue;
            }

            // Confidence tercile thresholds from the as-of history.
            $confidences = array_column($history, 'confidence');
            sort($confidences);
            $lo = $confidences[(int) floor(count($confidences) / 3)];
            $hi = $confidences[(int) floor(2 * count($confidences) / 3)];

            $tercile = fn (float $c): int => $c >= $hi ? 2 : ($c >= $lo ? 1 : 0);
            $bucket = array_values(array_filter(
                $history,
                fn ($t) => $tercile($t['confidence']) === $tercile($trade['confidence']),
            ));

            if (count($bucket) < $minBucket) {
                $bucket = $history; // widen rather than guess
            }

            $wins = array_filter(array_column($bucket, 'net_return'), fn ($r) => $r > 0);
            $losses = array_filter(array_column($bucket, 'net_return'), fn ($r) => $r <= 0);

            // One-sided buckets are still informative: all-losses => p=0
            // (Kelly says don't bet), all-wins => p=1 (capped elsewhere).
            $avgWin = $wins === [] ? 0.0 : array_sum($wins) / count($wins);
            $avgLoss = $losses === [] ? 0.10 : abs(array_sum($losses) / count($losses));

            $stats[$i] = [
                'p' => count($wins) / count($bucket),
                'b' => max(min($avgWin / max($avgLoss, 1e-9), 5.0), 0.5),
            ];
        }

        return $stats;
    }

    /** @param array<int, float> $equitySeries */
    protected function maxDrawdown(array $equitySeries): float
    {
        $peak = -INF;
        $maxDd = 0.0;

        foreach ($equitySeries as $equity) {
            $peak = max($peak, $equity);
            $maxDd = max($maxDd, ($peak - $equity) / $peak);
        }

        return round($maxDd, 4);
    }

    /**
     * Align per-strategy curves on the union of dates with forward fill so
     * the frontend can chart them as one series set.
     *
     * @param  array<string, array<string, float>>  $curves
     * @return array<int, array<string, float|string>>
     */
    protected function mergeCurves(array $curves): array
    {
        $dates = [];

        foreach ($curves as $curve) {
            $dates = [...$dates, ...array_keys($curve)];
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        $last = array_fill_keys(array_keys($curves), (float) $this->options['initial_equity']);
        $merged = [];

        foreach ($dates as $date) {
            $point = ['date' => $date];

            foreach ($curves as $name => $curve) {
                if (isset($curve[$date])) {
                    $last[$name] = $curve[$date];
                }

                $point[$name] = $last[$name];
            }

            $merged[] = $point;
        }

        return $merged;
    }
}
