<?php

/**
 * Two-regime flagship run: full 12-month archive (2025 H2 + 2026 H1) through
 * the validated config (price/volume gates + 10% stop), with the 12-feature
 * confidence pipeline (dilution / short-flow / regime included) and Kelly
 * portfolio simulation. Run with:
 *   php artisan tinker scripts/flagship_two_regime.php
 */

use App\Jobs\Backtesting\RunBacktest;
use App\Models\BacktestEvent;
use App\Models\BacktestRun;

ini_set('memory_limit', '2048M');

$run = BacktestRun::create([
    'name' => 'two-regime flagship: gates + stop10 + 12-feature confidence',
    'status' => 'pending',
    'params' => [
        'from' => '2025-07-03',
        'to' => '2026-06-24',
        'threshold' => 0.65,
        'min_daily_mentions' => 3,
        'baseline_days' => 30,
        'cooldown_days' => 3,
        'hit_threshold' => 0.30,
        'friction' => 0.05,
        'max_entry_price' => 5,
        'min_volume_z' => 2,
        'stop_loss' => 0.10,
    ],
]);

$t0 = microtime(true);
(new RunBacktest($run->id))->handle(app(App\Services\Backtesting\Backtester::class));
$run->refresh();

printf("run #%d status=%s in %.0fs\n", $run->id, $run->status, microtime(true) - $t0);

$s = $run->results['summary'] ?? [];
printf(
    "signals=%d hit=%.3f base=%.3f net/trade=%+.2f%% pf=%s stops=%.2f\n",
    $s['signal_count'] ?? 0,
    $s['hit_rate'] ?? 0,
    $s['base_rate'] ?? 0,
    100 * ($s['avg_net_return_5d'] ?? 0),
    $s['profit_factor'] ?? '—',
    $s['stop_rate'] ?? 0,
);

// Per-half split: does the edge survive the 2025 H2 regime?
foreach (['2025 H2' => ['2025-07-03', '2026-01-01'], '2026 H1' => ['2026-01-01', '2026-07-01']] as $label => [$a, $b]) {
    $events = BacktestEvent::where('backtest_run_id', $run->id)
        ->where('fired', true)->where('day', '>=', $a)->where('day', '<', $b)->get();

    if ($events->isEmpty()) {
        printf("%s: no fired signals\n", $label);

        continue;
    }

    $net = $events->map(fn ($e) => $e->exit_return - 0.05);
    printf(
        "%s: signals=%d hit=%.3f net/trade=%+.2f%% pos=%.2f\n",
        $label,
        $events->count(),
        $events->avg(fn ($e) => $e->hit ? 1 : 0),
        100 * $net->avg(),
        $net->filter(fn ($r) => $r > 0)->count() / $events->count(),
    );
}

// New-feature coverage on events.
$total = BacktestEvent::where('backtest_run_id', $run->id)->count();
foreach (['short_ratio', 'share_growth_12m', 'market_ret_5d', 'site_mention_z'] as $f) {
    $n = BacktestEvent::where('backtest_run_id', $run->id)->whereNotNull($f)->count();
    printf("coverage %-16s %5.1f%%\n", $f, $total ? 100 * $n / $total : 0);
}

// Winner profile incl. the new dilution/short features.
foreach (($run->results['winner_profile'] ?? []) as $side => $p) {
    printf(
        "%-7s n=%-4d shortR=%s shareGrow=%s atm90=%s shelf=%s dollarVol=%s\n",
        $side,
        $p['count'],
        $p['median_short_ratio'] ?? '—',
        $p['median_share_growth_12m'] ?? '—',
        $p['atm_filed_90d_rate'] ?? '—',
        $p['active_shelf_rate'] ?? '—',
        $p['median_dollar_volume'] !== null ? number_format($p['median_dollar_volume']) : '—',
    );
}

$c = $run->results['confidence'] ?? [];
printf(
    "confidence: scored=%d/%d brier=%s (ref %s)\n",
    $c['events_scored'] ?? 0,
    $c['events_total'] ?? 0,
    $c['brier'] ?? '—',
    $c['brier_reference'] ?? '—',
);

foreach ($c['reliability'] ?? [] as $i => $b) {
    printf("  q%d: predicted %.3f realized %.3f (n=%d)\n", $i + 1, $b['predicted'], $b['realized'], $b['count']);
}

foreach (($run->results['portfolio']['strategies'] ?? []) as $name => $st) {
    printf(
        "%-11s return=%+.1f%% maxDD=%.1f%% taken=%d skipped=%d avgpos=%s\n",
        $name,
        100 * $st['total_return'],
        100 * $st['max_drawdown'],
        $st['trades_taken'],
        $st['trades_skipped'],
        $st['avg_position_pct'] !== null ? round(100 * $st['avg_position_pct'], 1).'%' : '—',
    );
}

echo "two-regime flagship done (run #{$run->id})\n";
