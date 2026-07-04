<?php

namespace App\Services\Backtesting;

use App\Models\BacktestEvent;
use App\Models\BacktestRun;
use App\Services\Ml\ConfidenceTrainer;
use App\Services\Ml\LogisticRegression;

/**
 * Research view over a run's full candidate set (fired + control — no
 * selection bias): fit P(hit | features) once on the first 70% of days and
 * report out-of-sample precision@k on the last 30%.
 *
 * The operational counterpart is ConfidenceTrainer, which does monthly
 * walk-forward scoring and persists the live model; both share the same
 * feature builder and LogisticRegression core.
 */
class WeightFitter
{
    /**
     * @return array<string, mixed>
     */
    public function fit(BacktestRun $run): array
    {
        $events = BacktestEvent::query()
            ->where('backtest_run_id', $run->id)
            ->orderBy('day')
            ->get()
            ->map(fn (BacktestEvent $e): array => [
                'day' => $e->day->toDateString(),
                'label' => $e->hit ? 1.0 : 0.0,
                'features' => ConfidenceTrainer::features($e->only([
                    'zscore', 'volume_z', 'sentiment', 'unique_authors', 'mentions',
                    'pre_return_3d', 'dollar_volume', 'short_ratio', 'atm_filed_90d',
                    'active_shelf', 'share_growth_12m', 'market_ret_5d', 'site_mention_z',
                    'vix', 'btc_ret_5d', 'mention_streak',
                ])),
            ])
            ->values();

        if ($events->count() < 100) {
            return ['error' => 'Not enough events to fit (need >= 100, have '.$events->count().').'];
        }

        $splitIdx = (int) floor($events->count() * 0.7);
        $splitDay = $events[$splitIdx]['day'];

        $train = $events->take($splitIdx)->all();
        $test = $events->slice($splitIdx)->values()->all();

        $params = (new LogisticRegression(ConfidenceTrainer::FEATURES))->fit($train);

        return [
            'run_id' => $run->id,
            'events' => $events->count(),
            'train_events' => count($train),
            'test_events' => count($test),
            'split_day' => $splitDay,
            'base_rate_test' => $this->baseRate($test),
            'weights' => array_map(fn ($w) => round($w, 4), $params['weights']),
            'bias' => round($params['bias'], 4),
            'precision_at_k' => $this->precisionAtK($test, $params),
        ];
    }

    /**
     * Out-of-sample: rank test events by predicted probability, report the
     * hit rate among the top-K — the "if we only traded the model's K most
     * confident picks" number.
     *
     * @param  array<int, array<string, mixed>>  $test
     * @param  array{weights: array<string, float>, bias: float, means: array<string, float>, sds: array<string, float>}  $params
     * @return array<string, array{k: int, precision: float, lift: ?float}>
     */
    protected function precisionAtK(array $test, array $params): array
    {
        $scored = array_map(fn ($row) => [
            'p' => LogisticRegression::predict($params, $row['features']),
            'label' => $row['label'],
        ], $test);

        usort($scored, fn ($a, $b) => $b['p'] <=> $a['p']);

        $baseRate = $this->baseRate($test);
        $out = [];

        foreach ([10, 25, 50, 100] as $k) {
            if (count($scored) < $k) {
                continue;
            }

            $top = array_slice($scored, 0, $k);
            $precision = array_sum(array_column($top, 'label')) / $k;

            $out["top_{$k}"] = [
                'k' => $k,
                'precision' => round($precision, 4),
                'lift' => $baseRate > 0 ? round($precision / $baseRate, 2) : null,
            ];
        }

        return $out;
    }

    /** @param array<int, array<string, mixed>> $rows */
    protected function baseRate(array $rows): float
    {
        return $rows === [] ? 0.0 : round(array_sum(array_column($rows, 'label')) / count($rows), 4);
    }
}
