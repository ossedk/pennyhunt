<?php

/**
 * One-off exit-rule sweep over the best gated configuration
 * (price <= $5, volume z >= 2). Run with:
 *   php artisan tinker scripts/exit_sweep.php
 */

use App\Models\BacktestRun;
use App\Services\Backtesting\Backtester;

ini_set('memory_limit', '1024M');

$base = [
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
];

$combos = [
    ['stop_loss' => null, 'take_profit' => null], // control: time exit only
    ['stop_loss' => 0.10, 'take_profit' => null],
    ['stop_loss' => 0.15, 'take_profit' => null],
    ['stop_loss' => null, 'take_profit' => 0.30],
    ['stop_loss' => 0.10, 'take_profit' => 0.30],
    ['stop_loss' => 0.15, 'take_profit' => 0.30],
    ['stop_loss' => 0.10, 'take_profit' => 0.50],
    ['stop_loss' => 0.15, 'take_profit' => 0.50],
    ['stop_loss' => 0.20, 'take_profit' => 0.50],
    ['stop_loss' => 0.10, 'take_profit' => 1.00],
];

foreach ($combos as $combo) {
    $params = array_filter([...$base, ...$combo], fn ($v) => $v !== null);
    $label = 'exit stop='.($combo['stop_loss'] ?? '—').' take='.($combo['take_profit'] ?? '—');

    $run = BacktestRun::create([
        'name' => $label,
        'status' => 'running',
        'params' => $params,
    ]);

    $t0 = microtime(true);
    app(Backtester::class)->run($run);
    $run->refresh();

    $s = $run->results['summary'];

    printf(
        "#%d %-28s signals=%d net/trade=%+.2f%% winrate=%s pf=%s stops=%s takes=%s time=%s (%.0fs)\n",
        $run->id,
        $label,
        $s['signal_count'],
        100 * ($s['avg_net_return_5d'] ?? 0),
        $s['net_positive_5d_rate'] ?? '—',
        $s['profit_factor'] ?? '—',
        $s['stop_rate'] ?? '—',
        $s['take_rate'] ?? '—',
        $s['time_exit_rate'] ?? '—',
        microtime(true) - $t0,
    );
}

echo "sweep done\n";
