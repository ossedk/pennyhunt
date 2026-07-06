<?php

namespace App\Services\Backtesting;

use App\Models\BacktestRun;
use App\Services\Features\LlmAggregates;
use App\Services\Features\MarketIntelligence;
use App\Services\Features\SectorHeat;
use App\Services\Features\TechnicalFeatures;
use App\Services\Signals\SignalMath;
use App\Support\AnalyticsGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Replays archived forum history through the production scoring formulas
 * (SignalMath) and grades every simulated signal against real market bars.
 *
 * Methodology guardrails:
 *  - As-of baselines: each day's z-score uses only the trailing N days BEFORE
 *    that day (zero-filled for silent days) — no look-ahead. Volume z-scores
 *    likewise use only bars at/before the signal day.
 *  - Entry at the NEXT trading day's open, never the signal-day close.
 *  - cross_source is null pre-July-2026 (no archived aggregator data); its
 *    weight is renormalized across the remaining components and reported.
 *  - Every scored candidate day (fired or not) is persisted to
 *    backtest_events, so hit rates always compare against the control base
 *    rate and weight fitting sees the unbiased candidate set.
 *  - Net metrics apply a round-trip friction haircut (params.friction,
 *    default 5%) approximating penny-stock spreads + slippage.
 *  - Reaction vs prediction: signals where price already ran >15% in the 3
 *    trading days before entry are labelled "reaction".
 *
 * Optional market-confirmation gates (the "buzz is early" experiment):
 *  - params.min_volume_z: only fire when signal-day volume z-score >= X.
 *  - params.max_pre_run: only fire when the 3-day pre-entry run-up <= X.
 *  - params.max_entry_price: only fire below a price cap (the penny-stock
 *    universe filter — +30%/5d is structurally implausible on mega-caps).
 *
 * Optional exit rules (stop/take asymmetry experiment):
 *  - params.stop_loss: exit when price falls X below entry (e.g. 0.15).
 *  - params.take_profit: exit when price rises X above entry (e.g. 0.30).
 *  Simulated on daily OHLC pessimistically: gaps through the stop fill at
 *  the open (worse than the stop), and when a single bar straddles both
 *  levels the stop is assumed to fill first. Untriggered positions exit at
 *  the day-5 close (the existing time exit).
 */
class Backtester
{
    protected const EVENT_COLUMNS = [
        'ticker_id', 'symbol', 'day', 'fired', 'composite', 'zscore', 'mentions',
        'unique_authors', 'sentiment', 'volume_z', 'dollar_volume', 'pre_return_3d',
        'short_ratio', 'atm_filed_90d', 'active_shelf', 'share_growth_12m',
        'market_ret_5d', 'site_mention_z', 'vix', 'btc_ret_5d', 'mention_streak',
        'llm_coverage', 'llm_direction', 'llm_conviction', 'llm_pump_suspicion',
        'llm_dd_share', 'llm_hype_share', 'llm_news_share', 'llm_catalyst_share',
        'rvol', 'atr_pct', 'range_expansion', 'dist_52w_high', 'up_streak', 'gap_open',
        'sector_heat', 'sector_mention_z', 'smallcap_rel_20d', 'xbi_ret_5d',
        'insider_buys_90d', 'insider_net_value_90d', 'news_catalyst_7d', 'news_offering_7d',
        'entry_date', 'entry', 'return_1d', 'return_3d', 'return_5d',
        'best_close_5d', 'exit_return', 'exit_reason', 'exit_day', 'exit_date',
        'hit', 'classification',
    ];

    /** @var array<string, mixed> */
    protected array $params;

    public function run(BacktestRun $run): void
    {
        $this->params = $run->params;

        $from = CarbonImmutable::parse($this->params['from']);
        $to = CarbonImmutable::parse($this->params['to']);

        $dailyStats = $this->dailyTickerStats($from, $to);
        $bars = $this->barsByTicker($dailyStats->keys()->all());
        $symbols = DB::table('tickers')->whereIn('id', $dailyStats->keys())->pluck('symbol', 'id');

        // Point-in-time dilution / short-flow / regime features (phase A).
        $intel = MarketIntelligence::load($dailyStats->keys()->all(), $from->toDateString(), $to->toDateString());

        // LLM post-classification aggregates (phase B feature block).
        $llm = LlmAggregates::load($dailyStats->keys()->all(), $from->toDateString(), $to->toDateString());

        // Sector sympathy features from the same bar arrays (phase D).
        $sector = SectorHeat::load($dailyStats->keys()->all(), $bars, $from->toDateString(), $to->toDateString());

        $run->events()->delete(); // idempotent re-runs

        $fromIdx = self::dayIndex($from->toDateString());
        $toIdx = self::dayIndex($to->toDateString());

        $events = [];

        foreach ($dailyStats as $tickerId => $days) {
            if (! isset($bars[$tickerId])) {
                continue; // no market data (delisted / unknown symbol) — can't grade either way
            }

            foreach ($this->replayTicker($days, $bars[$tickerId], $fromIdx, $toIdx) as $event) {
                $event['ticker_id'] = $tickerId;
                $event['symbol'] = $symbols[$tickerId];
                $events[] = [
                    ...$event,
                    ...$intel->features($tickerId, $event['day']),
                    ...$llm->features($tickerId, $event['day']),
                    ...$sector->features($tickerId, $event['day']),
                ];
            }
        }

        $this->persistEvents($run, $events);

        $fired = array_values(array_filter($events, fn ($e) => $e['fired']));
        $controls = array_values(array_filter($events, fn ($e) => ! $e['fired']));

        $run->forceFill([
            'status' => 'done',
            'finished_at' => now(),
            'results' => [
                'summary' => $this->summarize($fired, $controls),
                'winner_profile' => $this->winnerProfile($fired),
                'meta' => [
                    'tickers_evaluated' => $dailyStats->count(),
                    'tickers_with_bars' => count(array_intersect_key($bars, $dailyStats->all())),
                    'control_days' => count($controls),
                    'cross_source' => 'unavailable pre-2026-07 — weight renormalized',
                ],
            ],
        ])->save();
    }

    /**
     * Per-ticker, per-day mention aggregates within the window, keyed by
     * epoch-day index. Sparse — silent days are implicit zeros so memory stays
     * flat regardless of window length × ticker count.
     *
     * @return Collection<int, array<int, array{mentions: int, authors: int, sentiment: ?float}>>
     */
    protected function dailyTickerStats(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        // date() is portable between Postgres (UTC-pinned session) and SQLite (tests).
        $gate = AnalyticsGate::sourceJoin('p');

        $rows = DB::select(<<<SQL
            SELECT
                m.ticker_id,
                date(m.posted_at) AS day,
                COUNT(*) AS mentions,
                COUNT(DISTINCT p.author_id) AS authors,
                AVG(s.lexicon_score) AS sentiment
            FROM post_ticker_mentions m
            JOIN raw_posts p ON p.id = m.raw_post_id
            {$gate}
            LEFT JOIN post_sentiments s ON s.raw_post_id = p.id
            JOIN tickers t ON t.id = m.ticker_id AND t.is_active
            WHERE m.posted_at >= ? AND m.posted_at < ?
            GROUP BY m.ticker_id, day
            ORDER BY m.ticker_id, day
        SQL, [$from, $to]);

        $byTicker = [];

        foreach ($rows as $row) {
            $byTicker[(int) $row->ticker_id][self::dayIndex($row->day)] = [
                'mentions' => (int) $row->mentions,
                'authors' => (int) $row->authors,
                'sentiment' => $row->sentiment !== null ? (float) $row->sentiment : null,
            ];
        }

        return collect($byTicker);
    }

    /** Calendar days since the Unix epoch for a Y-m-d string. */
    protected static function dayIndex(string $date): int
    {
        return intdiv((int) strtotime($date.' 00:00:00 UTC'), 86400);
    }

    /**
     * @return array<int, array<int, array{date: string, open: float, high: float, low: float, close: float, volume: float}>>
     */
    protected function barsByTicker(array $tickerIds): array
    {
        $bars = [];

        DB::table('market_bars')
            ->whereIn('ticker_id', $tickerIds)
            ->where('interval', '1d')
            ->orderBy('bucket_start')
            ->select('ticker_id', 'bucket_start', 'open', 'high', 'low', 'close', 'volume')
            ->each(function ($bar) use (&$bars) {
                $bars[$bar->ticker_id][] = [
                    'date' => substr($bar->bucket_start, 0, 10),
                    'open' => (float) $bar->open,
                    'high' => (float) $bar->high,
                    'low' => (float) $bar->low,
                    'close' => (float) $bar->close,
                    'volume' => (float) $bar->volume,
                ];
            });

        return $bars;
    }

    /**
     * Walk one ticker's days chronologically (implicit zeros on silent days),
     * scoring every candidate day with as-of baselines. Yields one event row
     * per gradeable candidate, fired or control.
     *
     * @param  array<int, array{mentions: int, authors: int, sentiment: ?float}>  $days  keyed by epoch-day index
     * @param  array<int, array{date: string, open: float, high: float, low: float, close: float, volume: float}>  $bars
     * @return array<int, array<string, mixed>>
     */
    protected function replayTicker(array $days, array $bars, int $fromIdx, int $toIdx): array
    {
        $threshold = (float) $this->params['threshold'];
        $minMentions = (int) $this->params['min_daily_mentions'];
        $baselineDays = (int) $this->params['baseline_days'];
        $minBaselineObs = 10;
        $cooldownDays = (int) $this->params['cooldown_days'];
        $hitThreshold = (float) $this->params['hit_threshold'];
        $minVolumeZ = isset($this->params['min_volume_z']) ? (float) $this->params['min_volume_z'] : null;
        $maxPreRun = isset($this->params['max_pre_run']) ? (float) $this->params['max_pre_run'] : null;
        $maxEntryPrice = isset($this->params['max_entry_price']) ? (float) $this->params['max_entry_price'] : null;

        $barIndex = array_flip(array_column($bars, 'date'));

        $history = []; // trailing mention counts, oldest first
        $events = [];
        $lastFiredIdx = -PHP_INT_MAX;

        for ($idx = $fromIdx; $idx < $toIdx; $idx++) {
            $stats = $days[$idx] ?? ['mentions' => 0, 'authors' => 0, 'sentiment' => null];

            $baseline = $history;
            $history[] = $stats['mentions'];

            if (count($history) > $baselineDays) {
                array_shift($history);
            }

            if ($stats['mentions'] < $minMentions) {
                continue;
            }

            if (count($baseline) < $minBaselineObs) {
                continue; // not enough as-of history to form a baseline
            }

            $mean = array_sum($baseline) / count($baseline);
            $sd = $this->stddev($baseline, $mean);

            if ($sd <= 0) {
                continue;
            }

            $z = ($stats['mentions'] - $mean) / $sd;
            $date = gmdate('Y-m-d', $idx * 86400);

            $composite = SignalMath::composite([
                'acceleration' => SignalMath::acceleration($z),
                'breadth' => SignalMath::breadth($stats['authors'], $stats['mentions']),
                'sentiment' => SignalMath::sentiment($stats['sentiment']),
                'cross_source' => null, // no archived aggregator data
            ]);

            $outcome = $this->gradeDay($date, $bars, $barIndex, $hitThreshold);

            if ($outcome === null) {
                continue; // window not priceable — excluded from both buckets
            }

            $fires = $composite >= $threshold
                && ($idx - $lastFiredIdx) > $cooldownDays
                && ($minVolumeZ === null || ($outcome['volume_z'] !== null && $outcome['volume_z'] >= $minVolumeZ))
                && ($maxPreRun === null || ($outcome['pre_return_3d'] !== null && $outcome['pre_return_3d'] <= $maxPreRun))
                && ($maxEntryPrice === null || $outcome['entry'] <= $maxEntryPrice);

            if ($fires) {
                $lastFiredIdx = $idx;
            }

            $events[] = [
                'day' => $date,
                'fired' => $fires,
                'composite' => round($composite, 4),
                'zscore' => round($z, 2),
                'mentions' => $stats['mentions'],
                'unique_authors' => $stats['authors'],
                'sentiment' => $stats['sentiment'] !== null ? round($stats['sentiment'], 3) : null,
                ...$outcome,
            ];
        }

        return $events;
    }

    /**
     * Outcome + market context for a candidate day: entry next trading day at
     * open, forward returns at +1/+3/+5 trading closes, best close within 5
     * days, pre-entry 3-day run-up, and as-of volume features.
     *
     * @param  array<int, array{date: string, open: float, high: float, low: float, close: float, volume: float}>  $bars
     * @param  array<string, int>  $barIndex
     * @return array<string, mixed>|null null when bars can't price the window
     */
    protected function gradeDay(string $date, array $bars, array $barIndex, float $hitThreshold): ?array
    {
        // Find the first trading day AFTER the signal date (entry day).
        $entryIdx = null;

        if (isset($barIndex[$date])) {
            $entryIdx = $barIndex[$date] + 1;
        } else {
            // Signal on a weekend/holiday: next available bar.
            foreach ($bars as $i => $bar) {
                if ($bar['date'] > $date) {
                    $entryIdx = $i;
                    break;
                }
            }
        }

        if ($entryIdx === null || $entryIdx < 1 || ! isset($bars[$entryIdx]) || ! isset($bars[$entryIdx + 5])) {
            return null; // window not fully resolvable
        }

        $entry = $bars[$entryIdx]['open'];

        if ($entry <= 0) {
            return null;
        }

        // Split-adjustment seam guard: incremental bar syncs leave mixed
        // price bases around splits (SMCI 10:1 read as a fake +1095% trade).
        // A >4x or <0.25x overnight jump inside the grading window is a data
        // break, not a move — the event is ungradeable.
        for ($o = 0; $o <= 5; $o++) {
            $prevClose = $bars[$entryIdx + $o - 1]['close'];

            if ($prevClose <= 0) {
                return null;
            }

            $gap = $bars[$entryIdx + $o]['open'] / $prevClose;

            if ($gap > 4.0 || $gap < 0.25) {
                return null;
            }
        }

        $returnAt = fn (int $offset): float => round(($bars[$entryIdx + $offset]['close'] - $entry) / $entry, 4);

        $bestClose = 0.0;
        for ($o = 1; $o <= 5; $o++) {
            $bestClose = max($bestClose, ($bars[$entryIdx + $o]['close'] - $entry) / $entry);
        }

        // Run-up over the 3 trading days before entry (reaction detector).
        $preIdx = $entryIdx - 4;
        $preReturn = isset($bars[$preIdx]) && $bars[$preIdx]['close'] > 0
            ? round(($bars[$entryIdx - 1]['close'] - $bars[$preIdx]['close']) / $bars[$preIdx]['close'], 4)
            : null;

        // Volume context on the signal-day bar (last bar before entry), as-of:
        // z-score vs the trailing 30 bars before it.
        $sigIdx = $entryIdx - 1;
        $volumeZ = null;
        $dollarVolume = null;

        if (isset($bars[$sigIdx])) {
            $dollarVolume = round($bars[$sigIdx]['volume'] * $bars[$sigIdx]['close'], 2);

            $trailing = array_column(array_slice($bars, max(0, $sigIdx - 30), min(30, $sigIdx)), 'volume');

            if (count($trailing) >= 10) {
                $volMean = array_sum($trailing) / count($trailing);
                $volSd = $this->stddev($trailing, $volMean);

                if ($volSd > 0) {
                    $volumeZ = round(($bars[$sigIdx]['volume'] - $volMean) / $volSd, 2);
                }
            }
        }

        $exit = $this->simulateExit($bars, $entryIdx, $entry);

        // Technical features on the signal-day bar (as-of, phase D).
        $technicals = isset($bars[$sigIdx])
            ? TechnicalFeatures::compute($bars, $sigIdx)
            : array_fill_keys(TechnicalFeatures::FEATURE_KEYS, null);

        return [
            ...$technicals,
            'entry_date' => $bars[$entryIdx]['date'],
            'entry' => round($entry, 4),
            'return_1d' => $returnAt(1),
            'return_3d' => $returnAt(3),
            'return_5d' => $returnAt(5),
            'best_close_5d' => round($bestClose, 4),
            'exit_return' => $exit['return'],
            'exit_reason' => $exit['reason'],
            'exit_day' => $exit['day'],
            'exit_date' => $exit['date'],
            'hit' => $bestClose >= $hitThreshold,
            'pre_return_3d' => $preReturn,
            'volume_z' => $volumeZ,
            'dollar_volume' => $dollarVolume,
            'classification' => $preReturn !== null && $preReturn > 0.15 ? 'reaction' : 'prediction',
        ];
    }

    /**
     * Walk the holding window bar-by-bar applying stop-loss / take-profit
     * rules (params.stop_loss / params.take_profit, both optional fractions).
     *
     * Pessimistic daily-OHLC assumptions:
     *  - Entry-day (day 0) stop/take can trigger from the entry open onward.
     *  - A gap through the stop fills at the open (worse than the stop); a
     *    gap through the take fills at the open (better than the take —
     *    that's how a resting limit order actually fills).
     *  - When one bar straddles both levels, the stop is assumed first.
     *  - Nothing triggered => time exit at the day-5 close (== return_5d).
     *
     * @param  array<int, array{date: string, open: float, high: float, low: float, close: float, volume: float}>  $bars
     * @return array{return: float, reason: string, day: int, date: string}
     */
    protected function simulateExit(array $bars, int $entryIdx, float $entry): array
    {
        $stop = isset($this->params['stop_loss']) ? (float) $this->params['stop_loss'] : null;
        $take = isset($this->params['take_profit']) ? (float) $this->params['take_profit'] : null;

        $timeExit = fn (): array => [
            'return' => round(($bars[$entryIdx + 5]['close'] - $entry) / $entry, 4),
            'reason' => 'time',
            'day' => 5,
            'date' => $bars[$entryIdx + 5]['date'],
        ];

        if ($stop === null && $take === null) {
            return $timeExit();
        }

        $stopPrice = $stop !== null ? $entry * (1 - $stop) : null;
        $takePrice = $take !== null ? $entry * (1 + $take) : null;

        for ($o = 0; $o <= 5; $o++) {
            $bar = $bars[$entryIdx + $o];
            // On the entry day the fill is the entry open itself, so gap
            // logic only applies from day 1 onward.
            $open = $o === 0 ? $entry : $bar['open'];

            if ($stopPrice !== null && ($open <= $stopPrice || $bar['low'] <= $stopPrice)) {
                $fill = min($open, $stopPrice);

                return ['return' => round(($fill - $entry) / $entry, 4), 'reason' => 'stop', 'day' => $o, 'date' => $bar['date']];
            }

            if ($takePrice !== null && ($open >= $takePrice || $bar['high'] >= $takePrice)) {
                $fill = max($open, $takePrice);

                return ['return' => round(($fill - $entry) / $entry, 4), 'reason' => 'take', 'day' => $o, 'date' => $bar['date']];
            }
        }

        return $timeExit();
    }

    /** @param array<int, array<string, mixed>> $events */
    protected function persistEvents(BacktestRun $run, array $events): void
    {
        $now = now();

        $rows = array_map(function (array $event) use ($run, $now) {
            $row = array_intersect_key($event, array_flip(self::EVENT_COLUMNS));
            $row['backtest_run_id'] = $run->id;
            $row['created_at'] = $now;

            return $row;
        }, $events);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('backtest_events')->insert($chunk);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $fired
     * @param  array<int, array<string, mixed>>  $controls
     * @return array<string, mixed>
     */
    protected function summarize(array $fired, array $controls): array
    {
        $friction = (float) ($this->params['friction'] ?? 0.05);

        $rate = function (array $rows, string $key, ?callable $predicate = null): ?float {
            if ($rows === []) {
                return null;
            }

            $predicate ??= fn ($v) => (bool) $v;
            $hits = count(array_filter(array_column($rows, $key), $predicate));

            return round($hits / count($rows), 4);
        };

        $avg = function (array $rows, string $key): ?float {
            $values = array_filter(array_column($rows, $key), fn ($v) => $v !== null);

            return $values === [] ? null : round(array_sum($values) / count($values), 4);
        };

        $percentile = function (array $rows, string $key, float $p): ?float {
            $values = array_values(array_filter(array_column($rows, $key), fn ($v) => $v !== null));

            if ($values === []) {
                return null;
            }

            sort($values);

            return round($values[(int) floor((count($values) - 1) * $p)], 4);
        };

        $predictions = array_values(array_filter($fired, fn ($s) => $s['classification'] === 'prediction'));
        $reactions = array_values(array_filter($fired, fn ($s) => $s['classification'] === 'reaction'));

        // Friction-adjusted PnL: equal-weight one unit per trade, round-trip
        // haircut per trade, using the simulated exit (== return_5d when no
        // stop/take rules are set). Profit factor = net wins / net losses.
        $netReturns = array_map(fn ($s) => $s['exit_return'] - $friction, $fired);
        $netWins = array_sum(array_filter($netReturns, fn ($r) => $r > 0));
        $netLosses = abs(array_sum(array_filter($netReturns, fn ($r) => $r < 0)));

        $exitShare = fn (string $reason): ?float => $fired === []
            ? null
            : round(count(array_filter($fired, fn ($s) => $s['exit_reason'] === $reason)) / count($fired), 4);

        return [
            'signal_count' => count($fired),
            'hit_rate' => $rate($fired, 'hit'),
            'base_rate' => $rate($controls, 'hit'),
            'avg_return_1d' => $avg($fired, 'return_1d'),
            'avg_return_3d' => $avg($fired, 'return_3d'),
            'avg_return_5d' => $avg($fired, 'return_5d'),
            'median_return_5d' => $percentile($fired, 'return_5d', 0.5),
            'p10_return_5d' => $percentile($fired, 'return_5d', 0.1),
            'p90_return_5d' => $percentile($fired, 'return_5d', 0.9),
            'avg_best_close_5d' => $avg($fired, 'best_close_5d'),
            'control_avg_return_5d' => $avg($controls, 'return_5d'),
            'positive_5d_rate' => $rate($fired, 'return_5d', fn ($v) => $v > 0),
            'control_positive_5d_rate' => $rate($controls, 'return_5d', fn ($v) => $v > 0),
            'prediction_share' => $fired === [] ? null : round(count($predictions) / count($fired), 4),
            'prediction_hit_rate' => $rate($predictions, 'hit'),
            'reaction_hit_rate' => $rate($reactions, 'hit'),
            // Net-of-friction block (the tradability test), on simulated exits
            'friction' => $friction,
            'stop_loss' => isset($this->params['stop_loss']) ? (float) $this->params['stop_loss'] : null,
            'take_profit' => isset($this->params['take_profit']) ? (float) $this->params['take_profit'] : null,
            'avg_exit_return' => $avg($fired, 'exit_return'),
            'median_exit_return' => $percentile($fired, 'exit_return', 0.5),
            'stop_rate' => $exitShare('stop'),
            'take_rate' => $exitShare('take'),
            'time_exit_rate' => $exitShare('time'),
            'avg_net_return_5d' => $netReturns === [] ? null : round(array_sum($netReturns) / count($netReturns), 4),
            'net_positive_5d_rate' => $fired === [] ? null : round(count(array_filter($netReturns, fn ($r) => $r > 0)) / count($netReturns), 4),
            'profit_factor' => $netLosses > 0 ? round($netWins / $netLosses, 2) : null,
        ];
    }

    /**
     * What separates the big winners from the rest? Feature medians for hits
     * vs non-hits among fired signals — the raw material for tightening the
     * engine toward the fat tail.
     *
     * @param  array<int, array<string, mixed>>  $fired
     * @return array<string, mixed>|null
     */
    protected function winnerProfile(array $fired): ?array
    {
        $hits = array_values(array_filter($fired, fn ($s) => $s['hit']));
        $misses = array_values(array_filter($fired, fn ($s) => ! $s['hit']));

        if (count($hits) < 5) {
            return null; // not enough winners to profile
        }

        $median = function (array $rows, string $key): ?float {
            $values = array_values(array_filter(array_column($rows, $key), fn ($v) => $v !== null));

            if ($values === []) {
                return null;
            }

            sort($values);

            return round($values[intdiv(count($values), 2)], 4);
        };

        $profile = fn (array $rows): array => [
            'count' => count($rows),
            'median_entry_price' => $median($rows, 'entry'),
            'median_dollar_volume' => $median($rows, 'dollar_volume'),
            'median_volume_z' => $median($rows, 'volume_z'),
            'median_mention_z' => $median($rows, 'zscore'),
            'median_mentions' => $median($rows, 'mentions'),
            'median_sentiment' => $median($rows, 'sentiment'),
            'median_pre_return_3d' => $median($rows, 'pre_return_3d'),
            'median_short_ratio' => $median($rows, 'short_ratio'),
            'median_share_growth_12m' => $median($rows, 'share_growth_12m'),
            'median_vix' => $median($rows, 'vix'),
            'median_btc_ret_5d' => $median($rows, 'btc_ret_5d'),
            'median_mention_streak' => $median($rows, 'mention_streak'),
            'atm_filed_90d_rate' => $rows === [] ? null : round(count(array_filter($rows, fn ($r) => ! empty($r['atm_filed_90d']))) / count($rows), 4),
            'active_shelf_rate' => $rows === [] ? null : round(count(array_filter($rows, fn ($r) => ! empty($r['active_shelf']))) / count($rows), 4),
        ];

        return [
            'winners' => $profile($hits),
            'losers' => $profile($misses),
        ];
    }

    /** @param array<int, int|float> $values */
    protected function stddev(array $values, float $mean): float
    {
        $n = count($values);

        if ($n < 2) {
            return 0.0;
        }

        $sumSquares = 0.0;

        foreach ($values as $value) {
            $sumSquares += ($value - $mean) ** 2;
        }

        return sqrt($sumSquares / ($n - 1));
    }
}
