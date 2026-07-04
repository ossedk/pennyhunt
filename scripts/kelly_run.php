<?php

/**
 * One-off: run the validated config (price/volume gates + 10% stop) with the
 * new exit_date + confidence + portfolio pipeline. Run with:
 *   php artisan tinker scripts/kelly_run.php
 */

use App\Jobs\Backtesting\RunBacktest;
use App\Models\BacktestRun;

ini_set('memory_limit', '1024M');

$run = BacktestRun::create([
    'name' => 'gated + stop10 + kelly',
    'status' => 'pending',
    'params' => [
        'from' => '2026-01-03',
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
    "signals=%d hit=%.3f net/trade=%+.2f%% pf=%s\n",
    $s['signal_count'] ?? 0,
    $s['hit_rate'] ?? 0,
    100 * ($s['avg_net_return_5d'] ?? 0),
    $s['profit_factor'] ?? '—',
);

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

$p = $run->results['portfolio'] ?? [];

foreach ($p['strategies'] ?? [] as $name => $st) {
    printf(
        "%-11s final=%s return=%+.1f%% maxDD=%.1f%% taken=%d skipped=%d liqcap=%d avgpos=%s\n",
        $name,
        number_format($st['final_equity']),
        100 * $st['total_return'],
        100 * $st['max_drawdown'],
        $st['trades_taken'],
        $st['trades_skipped'],
        $st['liquidity_capped'],
        $st['avg_position_pct'] !== null ? round(100 * $st['avg_position_pct'], 1).'%' : '—',
    );
}

echo "kelly run done\n";
