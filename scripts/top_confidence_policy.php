<?php

/**
 * Top-confidence-only policy simulation over run #31's fired events.
 *
 * Policy: trade a fired signal only when its walk-forward confidence is at
 * or above the trailing p50/p75/p90 of all PRIOR fired-event confidences —
 * cutoffs use only information available before the trade (the confidence
 * itself is already walk-forward), so there is no look-ahead anywhere.
 *
 * Run with: php -d memory_limit=1024M artisan tinker scripts/top_confidence_policy.php
 */

use App\Models\BacktestEvent;

const FRICTION = 0.05;
const MIN_TRAILING = 30;

$runId = (int) (getenv('RUN_ID') ?: 31);

$fired = BacktestEvent::query()
    ->where('backtest_run_id', $runId)
    ->where('fired', true)
    ->whereNotNull('confidence')
    ->orderBy('day')
    ->orderBy('id')
    ->get(['day', 'confidence', 'hit', 'exit_return'])
    ->map(fn ($e) => [
        'day' => $e->day->toDateString(),
        'confidence' => (float) $e->confidence,
        'hit' => (bool) $e->hit,
        'net' => (float) $e->exit_return - FRICTION,
    ])
    ->values()
    ->all();

printf("run #%d: %d fired events with walk-forward confidence\n\n", $runId, count($fired));

$stats = function (array $trades, string $label): void {
    if ($trades === []) {
        printf("%-28s no trades\n", $label);

        return;
    }

    $n = count($trades);
    $nets = array_column($trades, 'net');
    $wins = array_sum(array_filter($nets, fn ($r) => $r > 0));
    $losses = abs(array_sum(array_filter($nets, fn ($r) => $r < 0)));

    printf(
        "%-28s n=%-4d hit=%.3f net/trade=%+.2f%% pos=%.2f pf=%s\n",
        $label,
        $n,
        count(array_filter($trades, fn ($t) => $t['hit'])) / $n,
        100 * array_sum($nets) / $n,
        count(array_filter($nets, fn ($r) => $r > 0)) / $n,
        $losses > 0 ? round($wins / $losses, 2) : '∞',
    );
};

foreach ([0.0, 0.5, 0.75, 0.9] as $quantile) {
    $taken = [];
    $trailing = [];

    foreach ($fired as $event) {
        $enough = count($trailing) >= MIN_TRAILING;

        if ($quantile === 0.0) {
            $take = true; // baseline: trade everything
        } elseif (! $enough) {
            $take = false; // no distribution yet — stand aside
        } else {
            $sorted = $trailing;
            sort($sorted);
            $cutoff = $sorted[(int) floor((count($sorted) - 1) * $quantile)];
            $take = $event['confidence'] >= $cutoff;
        }

        if ($take) {
            $taken[] = $event;
        }

        $trailing[] = $event['confidence'];
    }

    $label = $quantile === 0.0 ? 'baseline (all fired)' : sprintf('confidence >= trailing p%02d', 100 * $quantile);
    $stats($taken, $label);

    foreach (['2025 H2' => ['2025-07-01', '2026-01-01'], '2026 H1' => ['2026-01-01', '2026-07-01']] as $half => [$a, $b]) {
        $subset = array_values(array_filter($taken, fn ($t) => $t['day'] >= $a && $t['day'] < $b));
        $stats($subset, "  {$half}");
    }

    echo "\n";
}

// Absolute-cutoff variant: does a fixed "model says >X%" rule work better
// than a relative rank? (Still no look-ahead: the threshold is a constant.)
foreach ([0.06, 0.08, 0.10] as $cut) {
    $taken = array_values(array_filter($fired, fn ($t) => $t['confidence'] >= $cut));
    $stats($taken, sprintf('confidence >= %.2f (abs)', $cut));
}

echo "top-confidence policy done\n";
