<?php

/**
 * Backfills the new macro/momentum features (vix, btc_ret_5d, mention_streak)
 * onto run #31's backtest_events, then answers two questions on the fired set:
 *
 *   1. Macro: does the regime (IWM 5d, VIX, BTC 5d) explain when signals die?
 *   2. Momentum: do multi-day building mention streaks outperform one-shot spikes?
 *
 * Run: php -d memory_limit=2048M artisan tinker scripts/macro_momentum_analysis.php
 */

use App\Models\BacktestEvent;
use App\Models\BacktestRun;
use App\Services\Features\MarketIntelligence;
use Illuminate\Support\Facades\DB;

$runId = 31;
$run = BacktestRun::findOrFail($runId);

$from = $run->params['from'];
$to = $run->params['to'];

echo "Run #{$runId} ({$from} -> {$to})\n";

// ---------------------------------------------------------------------------
// 1. Backfill vix / btc_ret_5d / mention_streak onto the run's events
// ---------------------------------------------------------------------------
$tickerIds = BacktestEvent::where('backtest_run_id', $runId)->distinct()->pluck('ticker_id')->all();
$intel = MarketIntelligence::load($tickerIds, substr($from, 0, 10), substr($to, 0, 10));

$events = BacktestEvent::query()
    ->where('backtest_run_id', $runId)
    ->get(['id', 'ticker_id', 'day']);

echo 'Backfilling '.count($events)." events...\n";

$updates = [];

foreach ($events as $e) {
    $f = $intel->features($e->ticker_id, $e->day->toDateString());
    $updates[$e->id] = [
        'vix' => $f['vix'],
        'btc_ret_5d' => $f['btc_ret_5d'],
        'mention_streak' => $f['mention_streak'],
    ];
}

foreach (array_chunk($updates, 500, preserve_keys: true) as $chunk) {
    $vixCase = $btcCase = $streakCase = [];
    $ids = [];

    foreach ($chunk as $id => $vals) {
        $ids[] = $id;
        $vixCase[] = 'WHEN '.$id.' THEN '.($vals['vix'] ?? 'NULL');
        $btcCase[] = 'WHEN '.$id.' THEN '.($vals['btc_ret_5d'] ?? 'NULL');
        $streakCase[] = 'WHEN '.$id.' THEN '.$vals['mention_streak'];
    }

    DB::update('UPDATE backtest_events SET'
        .' vix = CASE id '.implode(' ', $vixCase).' END,'
        .' btc_ret_5d = CASE id '.implode(' ', $btcCase).' END,'
        .' mention_streak = CASE id '.implode(' ', $streakCase).' END'
        .' WHERE id IN ('.implode(',', $ids).')');
}

echo "Backfill done.\n\n";

// ---------------------------------------------------------------------------
// 2. Conditional performance slices on the FIRED set
// ---------------------------------------------------------------------------
$friction = (float) ($run->params['friction'] ?? 0.05);

$fired = BacktestEvent::query()
    ->where('backtest_run_id', $runId)
    ->where('fired', true)
    ->get(['hit', 'exit_return', 'market_ret_5d', 'vix', 'btc_ret_5d', 'mention_streak', 'site_mention_z']);

$slice = function ($rows, string $label) use ($friction): void {
    if (count($rows) === 0) {
        printf("  %-28s  (no trades)\n", $label);

        return;
    }

    $n = count($rows);
    $hits = $rows->where('hit', true)->count();
    $net = $rows->avg(fn ($r) => $r->exit_return - $friction);

    printf("  %-28s n=%-4d hit=%5.1f%%  avg net exit=%+6.2f%%\n", $label, $n, 100 * $hits / $n, 100 * $net);
};

echo "=== MACRO: small-cap regime (IWM 5d return at signal) ===\n";
$slice($fired->filter(fn ($r) => $r->market_ret_5d !== null && $r->market_ret_5d <= -0.02), 'IWM 5d <= -2% (risk-off)');
$slice($fired->filter(fn ($r) => $r->market_ret_5d !== null && $r->market_ret_5d > -0.02 && $r->market_ret_5d < 0.02), 'IWM 5d -2%..+2% (neutral)');
$slice($fired->filter(fn ($r) => $r->market_ret_5d !== null && $r->market_ret_5d >= 0.02), 'IWM 5d >= +2% (risk-on)');

echo "\n=== MACRO: VIX level at signal ===\n";
$slice($fired->filter(fn ($r) => $r->vix !== null && $r->vix < 15), 'VIX < 15 (calm)');
$slice($fired->filter(fn ($r) => $r->vix !== null && $r->vix >= 15 && $r->vix < 20), 'VIX 15-20');
$slice($fired->filter(fn ($r) => $r->vix !== null && $r->vix >= 20 && $r->vix < 25), 'VIX 20-25');
$slice($fired->filter(fn ($r) => $r->vix !== null && $r->vix >= 25), 'VIX >= 25 (stress)');

echo "\n=== MACRO: BTC 5d return (retail risk appetite) ===\n";
$slice($fired->filter(fn ($r) => $r->btc_ret_5d !== null && $r->btc_ret_5d <= -0.05), 'BTC 5d <= -5%');
$slice($fired->filter(fn ($r) => $r->btc_ret_5d !== null && $r->btc_ret_5d > -0.05 && $r->btc_ret_5d < 0.05), 'BTC 5d -5%..+5%');
$slice($fired->filter(fn ($r) => $r->btc_ret_5d !== null && $r->btc_ret_5d >= 0.05), 'BTC 5d >= +5%');

echo "\n=== MACRO: site-wide buzz z (whole-casino heat) ===\n";
$slice($fired->filter(fn ($r) => $r->site_mention_z !== null && $r->site_mention_z < 0), 'site z < 0 (cooling)');
$slice($fired->filter(fn ($r) => $r->site_mention_z !== null && $r->site_mention_z >= 0 && $r->site_mention_z < 1.5), 'site z 0..1.5');
$slice($fired->filter(fn ($r) => $r->site_mention_z !== null && $r->site_mention_z >= 1.5), 'site z >= 1.5 (running hot)');

echo "\n=== MOMENTUM: consecutive rising-mention days at signal ===\n";
$slice($fired->filter(fn ($r) => $r->mention_streak === 0), 'streak 0 (spike not building)');
$slice($fired->filter(fn ($r) => $r->mention_streak === 1), 'streak 1');
$slice($fired->filter(fn ($r) => $r->mention_streak === 2), 'streak 2');
$slice($fired->filter(fn ($r) => $r->mention_streak >= 3), 'streak 3+ (sustained build)');

// Control comparison for momentum: does streak predict hits among ALL candidates?
echo "\n=== MOMENTUM among ALL candidates (fired + control) — hit base rates ===\n";
$all = BacktestEvent::query()
    ->where('backtest_run_id', $runId)
    ->get(['hit', 'mention_streak']);

foreach ([[0, 0], [1, 1], [2, 2], [3, 99]] as [$lo, $hi]) {
    $bucket = $all->filter(fn ($r) => $r->mention_streak >= $lo && $r->mention_streak <= $hi);

    if (count($bucket) > 0) {
        printf("  streak %d%s  n=%-5d hit rate=%5.1f%%\n", $lo, $hi > $lo ? '+' : ' ', count($bucket), 100 * $bucket->where('hit', true)->count() / count($bucket));
    }
}

echo "\nDone.\n";
