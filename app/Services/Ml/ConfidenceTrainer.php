<?php

namespace App\Services\Ml;

use App\Models\BacktestEvent;
use App\Models\BacktestRun;
use App\Models\SignalModel;
use Illuminate\Support\Facades\DB;

/**
 * Turns the weight-fitting research (P(hit | features), logistic) into an
 * operational confidence pipeline:
 *
 *  1. walkForwardScore(): monthly walk-forward over a run's backtest_events.
 *     Each month's events are scored by a model trained ONLY on prior months
 *     — no look-ahead — and the probability is written to events.confidence.
 *     This is the input the Kelly portfolio simulation needs.
 *
 *  2. train(): fits a final model on the full run, reports honest holdout
 *     metrics (Brier score, reliability-by-decile, precision@k) and persists
 *     it as the active SignalModel used to score LIVE signals at fire time.
 *
 * Calibration is first-class because Kelly sizing amplifies miscalibration:
 * an overconfident model over-bets exactly where it is most wrong.
 */
class ConfidenceTrainer
{
    public const FEATURES = [
        // Social + market microstructure (v1 set)
        'zscore', 'volume_z', 'sentiment', 'breadth', 'pre_return_3d', 'log_dollar_volume',
        // Phase A: dilution / short flow / regime (point-in-time via MarketIntelligence)
        'short_ratio', 'atm_filed_90d', 'active_shelf', 'share_growth_12m', 'market_ret_5d', 'site_mention_z',
        // Macro regime + momentum continuation
        'vix', 'btc_ret_5d', 'mention_streak',
        // Phase B: LLM post-classification aggregates (via LlmAggregates).
        // Coverage first so the model can learn to discount thin days.
        'llm_coverage', 'llm_direction', 'llm_conviction', 'llm_pump_suspicion',
        'llm_dd_share', 'llm_hype_share', 'llm_news_share', 'llm_catalyst_share',
        // Phase D: technicals (TechnicalFeatures), sector sympathy (SectorHeat),
        // small-cap risk appetite, insider flow, news catalysts.
        'rvol', 'atr_pct', 'range_expansion', 'dist_52w_high', 'up_streak', 'gap_open',
        'sector_heat', 'sector_mention_z', 'smallcap_rel_20d', 'xbi_ret_5d',
        'insider_buys_90d', 'insider_net_value_90d', 'news_catalyst_7d', 'news_offering_7d',
    ];

    protected const MIN_TRAIN_EVENTS = 300;

    /** Raw backtest_events columns the feature builder consumes. */
    public const RAW_INPUT_COLUMNS = [
        'zscore', 'volume_z', 'sentiment', 'unique_authors', 'mentions',
        'pre_return_3d', 'dollar_volume', 'short_ratio', 'atm_filed_90d',
        'active_shelf', 'share_growth_12m', 'market_ret_5d', 'site_mention_z',
        'vix', 'btc_ret_5d', 'mention_streak',
        'llm_coverage', 'llm_direction', 'llm_conviction', 'llm_pump_suspicion',
        'llm_dd_share', 'llm_hype_share', 'llm_news_share', 'llm_catalyst_share',
        'rvol', 'atr_pct', 'range_expansion', 'dist_52w_high', 'up_streak', 'gap_open',
        'sector_heat', 'sector_mention_z', 'smallcap_rel_20d', 'xbi_ret_5d',
        'insider_buys_90d', 'insider_net_value_90d', 'news_catalyst_7d', 'news_offering_7d',
    ];

    /**
     * Shared feature builder so the trainer and the live SignalEngine can
     * never drift apart. Takes named raw inputs (nulls tolerated — unknown
     * maps to a neutral 0.0 after standardization-by-training-mean, which the
     * LogisticRegression core handles via stored means).
     *
     * @param  array<string, mixed>  $in
     * @return array<string, float>
     */
    public static function features(array $in): array
    {
        $mentions = (int) ($in['mentions'] ?? 0);
        $uniqueAuthors = (int) ($in['unique_authors'] ?? 0);

        return [
            'zscore' => (float) ($in['zscore'] ?? 0.0),
            'volume_z' => (float) ($in['volume_z'] ?? 0.0),
            'sentiment' => (float) ($in['sentiment'] ?? 0.0),
            'breadth' => $mentions > 0 ? min($uniqueAuthors / $mentions, 1.0) : 0.0,
            'pre_return_3d' => (float) ($in['pre_return_3d'] ?? 0.0),
            'log_dollar_volume' => log(max((float) ($in['dollar_volume'] ?? 1.0), 1.0)),
            'short_ratio' => (float) ($in['short_ratio'] ?? 0.0),
            'atm_filed_90d' => empty($in['atm_filed_90d']) ? 0.0 : 1.0,
            'active_shelf' => empty($in['active_shelf']) ? 0.0 : 1.0,
            'share_growth_12m' => (float) ($in['share_growth_12m'] ?? 0.0),
            'market_ret_5d' => (float) ($in['market_ret_5d'] ?? 0.0),
            'site_mention_z' => (float) ($in['site_mention_z'] ?? 0.0),
            // Null VIX (pre-coverage) maps to a calm-market 20 rather than an
            // impossible 0 that would distort standardization.
            'vix' => isset($in['vix']) && $in['vix'] !== '' ? (float) $in['vix'] : 20.0,
            'btc_ret_5d' => (float) ($in['btc_ret_5d'] ?? 0.0),
            'mention_streak' => (float) ($in['mention_streak'] ?? 0),
            // LLM aggregates: null (pre-migration rows) and coverage-0 days
            // are the same "we know nothing" vector.
            'llm_coverage' => (float) ($in['llm_coverage'] ?? 0.0),
            'llm_direction' => (float) ($in['llm_direction'] ?? 0.0),
            'llm_conviction' => (float) ($in['llm_conviction'] ?? 0.0),
            'llm_pump_suspicion' => (float) ($in['llm_pump_suspicion'] ?? 0.0),
            'llm_dd_share' => (float) ($in['llm_dd_share'] ?? 0.0),
            'llm_hype_share' => (float) ($in['llm_hype_share'] ?? 0.0),
            'llm_news_share' => (float) ($in['llm_news_share'] ?? 0.0),
            'llm_catalyst_share' => (float) ($in['llm_catalyst_share'] ?? 0.0),
            // Phase D. Null defaults are the neutral state, not zero-is-bad:
            // rvol 1.0 = normal volume; dist_52w_high -0.5 = mid-range (0
            // would falsely claim "at the 52w high").
            'rvol' => isset($in['rvol']) && $in['rvol'] !== '' ? (float) $in['rvol'] : 1.0,
            'atr_pct' => (float) ($in['atr_pct'] ?? 0.0),
            'range_expansion' => isset($in['range_expansion']) && $in['range_expansion'] !== '' ? (float) $in['range_expansion'] : 1.0,
            'dist_52w_high' => isset($in['dist_52w_high']) && $in['dist_52w_high'] !== '' ? (float) $in['dist_52w_high'] : -0.5,
            'up_streak' => (float) ($in['up_streak'] ?? 0),
            'gap_open' => (float) ($in['gap_open'] ?? 0.0),
            'sector_heat' => (float) ($in['sector_heat'] ?? 0.0),
            'sector_mention_z' => (float) ($in['sector_mention_z'] ?? 0.0),
            'smallcap_rel_20d' => (float) ($in['smallcap_rel_20d'] ?? 0.0),
            'xbi_ret_5d' => (float) ($in['xbi_ret_5d'] ?? 0.0),
            'insider_buys_90d' => (float) ($in['insider_buys_90d'] ?? 0),
            'insider_net_value_90d' => (float) ($in['insider_net_value_90d'] ?? 0.0),
            'news_catalyst_7d' => empty($in['news_catalyst_7d']) ? 0.0 : 1.0,
            'news_offering_7d' => empty($in['news_offering_7d']) ? 0.0 : 1.0,
        ];
    }

    /**
     * Score every event of the run walk-forward (monthly refits) and persist
     * confidence per event. Returns out-of-sample quality metrics over all
     * scored events.
     *
     * @return array<string, mixed>
     */
    public function walkForwardScore(BacktestRun $run): array
    {
        $events = $this->eventRows($run);

        $byMonth = [];

        foreach ($events as $event) {
            $byMonth[substr($event['day'], 0, 7)][] = $event;
        }

        ksort($byMonth);

        $regression = new LogisticRegression(self::FEATURES);
        $history = [];
        $scored = [];

        foreach ($byMonth as $rows) {
            if (count($history) >= self::MIN_TRAIN_EVENTS) {
                $params = $regression->fit($history);

                $updates = [];

                foreach ($rows as $row) {
                    $p = round(LogisticRegression::predict($params, $row['features']), 4);
                    $updates[$row['id']] = $p;
                    $scored[] = ['p' => $p, 'label' => $row['label']];
                }

                $this->persistConfidence($updates);
            }

            $history = [...$history, ...$rows];
        }

        return [
            'events_total' => count($events),
            'events_scored' => count($scored),
            ...$this->qualityMetrics($scored),
        ];
    }

    /**
     * Fit the final model on the full run (holdout metrics on the last 30%),
     * persist it, and activate it for live scoring.
     */
    public function train(BacktestRun $run): SignalModel|array
    {
        $events = $this->eventRows($run);

        if (count($events) < self::MIN_TRAIN_EVENTS) {
            return ['error' => 'Not enough events to train (need >= '.self::MIN_TRAIN_EVENTS.', have '.count($events).').'];
        }

        $regression = new LogisticRegression(self::FEATURES);

        // Honest metrics: train on first 70%, evaluate on the last 30%.
        $splitIdx = (int) floor(count($events) * 0.7);
        $holdoutParams = $regression->fit(array_slice($events, 0, $splitIdx));

        $holdout = array_map(fn (array $row): array => [
            'p' => round(LogisticRegression::predict($holdoutParams, $row['features']), 4),
            'label' => $row['label'],
        ], array_slice($events, $splitIdx));

        // The shipped model uses everything we have.
        $params = $regression->fit($events);

        DB::transaction(function () use ($run, $events, $params, $holdout, &$model) {
            SignalModel::query()->update(['is_active' => false]);

            $model = SignalModel::create([
                'version' => 'v'.now()->format('Y-m-d').'-run'.$run->id.'.'.(SignalModel::count() + 1),
                'backtest_run_id' => $run->id,
                'train_from' => $events[0]['day'],
                'train_to' => $events[count($events) - 1]['day'],
                'train_events' => count($events),
                'parameters' => [
                    'weights' => array_map(fn ($w) => round($w, 6), $params['weights']),
                    'bias' => round($params['bias'], 6),
                    'means' => array_map(fn ($m) => round($m, 6), $params['means']),
                    'sds' => array_map(fn ($s) => round($s, 6), $params['sds']),
                ],
                'metrics' => [
                    'holdout_events' => count($holdout),
                    ...$this->qualityMetrics($holdout),
                ],
                'is_active' => true,
            ]);
        });

        return $model;
    }

    /**
     * Brier score, base rate and a reliability table (predicted vs realized
     * hit rate per probability bucket) — the calibration evidence Kelly
     * sizing depends on.
     *
     * @param  array<int, array{p: float, label: float}>  $scored
     * @return array<string, mixed>
     */
    protected function qualityMetrics(array $scored): array
    {
        if ($scored === []) {
            return ['brier' => null, 'base_rate' => null, 'reliability' => []];
        }

        $brier = 0.0;

        foreach ($scored as $row) {
            $brier += ($row['p'] - $row['label']) ** 2;
        }

        $baseRate = array_sum(array_column($scored, 'label')) / count($scored);

        // Reference Brier of always predicting the base rate: skill means
        // beating this, not just being a small number.
        $brierRef = $baseRate * (1 - $baseRate);

        // Reliability: sort by p, split into quintiles.
        usort($scored, fn ($a, $b) => $a['p'] <=> $b['p']);
        $reliability = [];

        foreach (array_chunk($scored, max((int) ceil(count($scored) / 5), 1)) as $bucket) {
            $reliability[] = [
                'count' => count($bucket),
                'predicted' => round(array_sum(array_column($bucket, 'p')) / count($bucket), 4),
                'realized' => round(array_sum(array_column($bucket, 'label')) / count($bucket), 4),
            ];
        }

        return [
            'brier' => round($brier / count($scored), 5),
            'brier_reference' => round($brierRef, 5),
            'base_rate' => round($baseRate, 4),
            'reliability' => $reliability,
        ];
    }

    /**
     * @return array<int, array{id: int, day: string, label: float, features: array<string, float>}>
     */
    protected function eventRows(BacktestRun $run): array
    {
        return BacktestEvent::query()
            ->where('backtest_run_id', $run->id)
            ->orderBy('day')
            ->orderBy('id')
            ->get()
            ->map(fn (BacktestEvent $e): array => [
                'id' => $e->id,
                'day' => $e->day->toDateString(),
                'label' => $e->hit ? 1.0 : 0.0,
                'features' => self::features($e->only(self::RAW_INPUT_COLUMNS)),
            ])
            ->values()
            ->all();
    }

    /** @param array<int, float> $confidenceByEventId */
    protected function persistConfidence(array $confidenceByEventId): void
    {
        foreach (array_chunk($confidenceByEventId, 500, preserve_keys: true) as $chunk) {
            $cases = [];
            $ids = [];

            foreach ($chunk as $id => $p) {
                $ids[] = (int) $id;
                $cases[] = 'WHEN '.((int) $id).' THEN '.$p;
            }

            DB::update(
                'UPDATE backtest_events SET confidence = CASE id '.implode(' ', $cases).' END WHERE id IN ('.implode(',', $ids).')',
            );
        }
    }
}
