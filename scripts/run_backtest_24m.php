<?php

/**
 * 24-month flagship backtest (third regime: 2024 H2).
 *
 * Same configuration as run #31 for comparability, extended window:
 * from 2024-08-02 (30 days after archive start, so baselines carry real
 * history from day one — no warm-up artifact) to 2026-06-24 (leaves 5+
 * sessions for exit grading). Executed synchronously (not through the
 * queue) because a 24-month replay exceeds the RunBacktest job timeout.
 *
 * Usage: php -d memory_limit=3072M artisan tinker --execute="require 'scripts/run_backtest_24m.php';"
 */

use App\Jobs\Backtesting\RunBacktest;
use App\Models\BacktestRun;
use App\Services\Backtesting\Backtester;

$run = BacktestRun::create([
    'name' => '24-month flagship: gates + stop10 + 15-feature confidence (3rd regime)',
    'status' => 'pending',
    'params' => [
        'from' => '2024-08-02',
        'to' => '2026-06-24',
        'threshold' => 0.65,
        'min_daily_mentions' => 3,
        'baseline_days' => 30,
        'cooldown_days' => 3,
        'hit_threshold' => 0.3,
        'friction' => 0.05,
        'stop_loss' => 0.1,
        'min_volume_z' => 2,
        'max_entry_price' => 5,
    ],
]);

echo 'Created backtest run #'.$run->id.PHP_EOL;

$startedAt = microtime(true);

(new RunBacktest($run->id))->handle(app(Backtester::class));

$run->refresh();

echo 'Run #'.$run->id.' finished: status='.$run->status
    .' in '.round((microtime(true) - $startedAt) / 60, 1).' min'.PHP_EOL;

$summary = collect($run->results ?? [])->only([
    'events_total', 'signals_fired', 'hit_rate', 'control_hit_rate',
    'avg_net_return', 'profit_factor',
])->all();

echo json_encode($summary, JSON_PRETTY_PRINT).PHP_EOL;
