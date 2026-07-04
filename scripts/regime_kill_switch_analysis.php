<?php

/**
 * Regime-conditional firing test on run #32 (24 months, 719 fired signals).
 *
 * Question: does suppressing signals in hostile regimes (VIX stress,
 * site-wide mention frenzy) turn the negative-expectancy configuration
 * positive? These two kill switches were identified in the macro audit
 * (VIX ≥ 25 bucket: −11.5%/trade; site z ≥ 1.5: hit rate halved) on run
 * #31 — this is the out-of-sample-ish check on the full 24-month window.
 *
 * Usage: php artisan tinker --execute="require 'scripts/regime_kill_switch_analysis.php';"
 */

use App\Models\BacktestEvent;

const RUN_ID = 32;
const FRICTION = 0.05;

$events = BacktestEvent::query()
    ->where('backtest_run_id', RUN_ID)
    ->where('fired', true)
    ->whereNotNull('exit_return')
    ->get(['day', 'exit_return', 'hit', 'vix', 'site_mention_z', 'market_ret_5d', 'confidence']);

$stats = function ($set) {
    $n = $set->count();

    if ($n === 0) {
        return ['n' => 0];
    }

    $net = $set->map(fn ($e) => $e->exit_return - FRICTION);
    $gains = $net->filter(fn ($r) => $r > 0)->sum();
    $losses = abs($net->filter(fn ($r) => $r < 0)->sum());

    return [
        'n' => $n,
        'hit_rate' => round($set->where('hit', true)->count() / $n, 4),
        'avg_net' => round($net->avg(), 4),
        'median_net' => round($net->sort()->values()[intdiv($n, 2)], 4),
        'pf' => $losses > 0 ? round($gains / $losses, 2) : null,
    ];
};

echo 'run #32 fired signals with priced exits: '.$events->count().PHP_EOL.PHP_EOL;

$slices = [
    'ALL' => fn ($e) => true,
    'vix < 25 (keep)' => fn ($e) => $e->vix !== null && $e->vix < 25,
    'vix >= 25 (killed)' => fn ($e) => $e->vix !== null && $e->vix >= 25,
    'site_z < 1.5 (keep)' => fn ($e) => $e->site_mention_z !== null && $e->site_mention_z < 1.5,
    'site_z >= 1.5 (killed)' => fn ($e) => $e->site_mention_z !== null && $e->site_mention_z >= 1.5,
    'BOTH switches pass' => fn ($e) => $e->vix !== null && $e->vix < 25 && ($e->site_mention_z === null || $e->site_mention_z < 1.5),
    'either switch trips' => fn ($e) => ($e->vix !== null && $e->vix >= 25) || ($e->site_mention_z !== null && $e->site_mention_z >= 1.5),
    'vix null (no data)' => fn ($e) => $e->vix === null,
];

foreach ($slices as $label => $filter) {
    $s = $stats($events->filter($filter));
    echo str_pad($label, 26).json_encode($s).PHP_EOL;
}

// Sweep VIX thresholds to see if 25 is special or arbitrary.
echo PHP_EOL.'VIX threshold sweep (keep vix < t):'.PHP_EOL;

foreach ([18, 20, 22, 25, 28, 30] as $t) {
    $s = $stats($events->filter(fn ($e) => $e->vix !== null && $e->vix < $t));
    echo "  t={$t}  ".json_encode($s).PHP_EOL;
}

echo PHP_EOL.'site_z threshold sweep (keep z < t):'.PHP_EOL;

foreach ([0.5, 1.0, 1.5, 2.0] as $t) {
    $s = $stats($events->filter(fn ($e) => $e->site_mention_z !== null && $e->site_mention_z < $t));
    echo "  t={$t}  ".json_encode($s).PHP_EOL;
}

// Stability check: kill-switch survivors split by calendar half.
echo PHP_EOL.'BOTH-pass survivors by half-year:'.PHP_EOL;

$halves = [
    '2024H2' => ['2024-08-01', '2025-01-01'],
    '2025H1' => ['2025-01-01', '2025-07-01'],
    '2025H2' => ['2025-07-01', '2026-01-01'],
    '2026H1' => ['2026-01-01', '2026-07-01'],
];

foreach ($halves as $label => [$from, $to]) {
    $s = $stats($events->filter(
        fn ($e) => $e->vix !== null && $e->vix < 25
            && ($e->site_mention_z === null || $e->site_mention_z < 1.5)
            && $e->day >= $from && $e->day < $to,
    ));
    echo "  {$label}  ".json_encode($s).PHP_EOL;
}

// Combined with confidence: kill switches + top-quintile only.
echo PHP_EOL.'BOTH-pass + confidence tiers:'.PHP_EOL;

$survivors = $events->filter(
    fn ($e) => $e->vix !== null && $e->vix < 25
        && ($e->site_mention_z === null || $e->site_mention_z < 1.5)
        && $e->confidence !== null,
);

foreach ([0.0, 0.5, 0.75, 0.9] as $pct) {
    $cut = $survivors->pluck('confidence')->sort()->values();
    $threshold = $cut[intval($pct * max(0, $cut->count() - 1))] ?? 0;
    $s = $stats($survivors->filter(fn ($e) => $e->confidence >= $threshold));
    echo '  conf p'.($pct * 100).' (>= '.round($threshold, 3).')  '.json_encode($s).PHP_EOL;
}
