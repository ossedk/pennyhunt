<?php

namespace App\Services\Signals;

use App\Events\SignalFired;
use App\Jobs\Nlp\GenerateSignalBrief;
use App\Models\AggregatorSnapshot;
use App\Models\MarketBar;
use App\Models\Signal;
use App\Models\SignalModel;
use App\Models\Ticker;
use App\Models\TickerMetric;
use App\Services\Features\LlmAggregates;
use App\Services\Features\MarketIntelligence;
use App\Services\Features\SectorHeat;
use App\Services\Features\TechnicalFeatures;
use App\Services\MarketData\YahooMarketData;
use App\Services\Ml\ConfidenceTrainer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Composite signal scoring (v1, heuristic weights).
 *
 * Components (each normalized to 0..1):
 *  - acceleration: z-score of hourly mention count vs the ticker's own baseline.
 *    Acceleration from a low baseline is THE core hypothesis: 0 -> 15 mentions/hr
 *    matters far more than a mega-cap going 500 -> 600.
 *  - breadth: unique authors / mentions (1.0 = fully organic, low = one account spamming)
 *  - sentiment: author-quality-weighted lexicon sentiment, shifted to 0..1
 *  - cross_source: ticker also rising on an independent aggregator (ApeWisdom/Tradestie)
 *
 * Weights are heuristic v1 placeholders; phase 4 fits them on graded signal
 * history (logistic regression baseline). Every fired signal stores its full
 * breakdown so refitting is possible retroactively.
 *
 * Component formulas and weights live in SignalMath, shared with the
 * Backtester so backtests replay the exact production scoring.
 *
 * Market-confirmation gate (backtest audit v2/v3): a candidate only fires
 * when its latest daily bar confirms tradability — close <= max_entry_price
 * AND volume z-score >= min_volume_z. This was the only net-positive
 * configuration in 6 months of replay; ungated buzz signals lose money.
 */
class SignalEngine
{
    public function __construct(protected YahooMarketData $marketData) {}

    public function run(): Collection
    {
        $threshold = (float) config('pennyhunt.signals.fire_threshold');
        $cooldownHours = (int) config('pennyhunt.signals.cooldown_hours');
        $minMentions = (int) config('pennyhunt.signals.min_hourly_mentions');

        $candidates = TickerMetric::query()
            ->where('interval', '1h')
            ->where('bucket_start', '>=', now()->subHours(2))
            ->where('mention_count', '>=', $minMentions)
            ->with('ticker')
            ->get()
            ->groupBy('ticker_id')
            ->map(fn (Collection $metrics) => $metrics->sortByDesc('bucket_start')->first());

        $risingOnAggregators = $this->risingAggregatorSymbols();
        $model = SignalModel::active();

        // Point-in-time dilution / short-flow / regime features for today,
        // same definitions the backtester + trainer use (MarketIntelligence),
        // plus today's LLM post-classification aggregates (LlmAggregates).
        $today = now()->toDateString();
        $intel = MarketIntelligence::load($candidates->keys()->all(), $today, $today);
        $llm = LlmAggregates::load($candidates->keys()->all(), $today, $today);
        $sector = SectorHeat::loadForDay($candidates->keys()->all(), $today);

        $fired = collect();

        foreach ($candidates as $metric) {
            $ticker = $metric->ticker;

            if ($ticker === null || ! $ticker->is_active) {
                continue;
            }

            $recentlyFired = Signal::query()
                ->where('ticker_id', $ticker->id)
                ->where('fired_at', '>=', now()->subHours($cooldownHours))
                ->exists();

            if ($recentlyFired) {
                continue;
            }

            $components = $this->scoreComponents($metric, $risingOnAggregators->contains($ticker->symbol));
            $composite = SignalMath::composite($components);

            if ($composite < $threshold) {
                continue;
            }

            $market = $this->marketGate($ticker);

            if ($market !== null && ! $market['passes']) {
                continue;
            }

            $intelFeatures = $intel->features($ticker->id, $today);
            $llmFeatures = $llm->features($ticker->id, $today);
            $sectorFeatures = $sector->features($ticker->id, $today);
            $confidence = $this->confidence($model, $metric, $market, [...$intelFeatures, ...$llmFeatures, ...$sectorFeatures]);

            $signal = Signal::create([
                'ticker_id' => $ticker->id,
                'fired_at' => now(),
                'composite_score' => round($composite, 4),
                'confidence' => $confidence,
                'model_version' => $confidence !== null ? $model->version : null,
                'breakdown' => [
                    'components' => $components,
                    'weights' => SignalMath::WEIGHTS,
                    'market_gate' => $market,
                    'intel' => $intelFeatures,
                    'llm' => $llmFeatures,
                    'sector' => $sectorFeatures,
                    'inputs' => [
                        'bucket_start' => $metric->bucket_start->toIso8601String(),
                        'mention_count' => $metric->mention_count,
                        'unique_authors' => $metric->unique_authors,
                        'weighted_sentiment' => $metric->weighted_sentiment,
                        'zscore_mentions' => $metric->zscore_mentions,
                        'author_quality_avg' => $metric->author_quality_avg,
                    ],
                ],
                'state' => 'new',
            ]);

            SignalFired::dispatch($signal);
            GenerateSignalBrief::dispatch($signal->id);
            $fired->push($signal);
        }

        return $fired;
    }

    /**
     * @return array{acceleration: float, breadth: float, sentiment: float, cross_source: float}
     */
    protected function scoreComponents(TickerMetric $metric, bool $risingCrossSource): array
    {
        return [
            'acceleration' => round(SignalMath::acceleration($metric->zscore_mentions), 4),
            'breadth' => round(SignalMath::breadth($metric->unique_authors, $metric->mention_count), 4),
            'sentiment' => round(SignalMath::sentiment($metric->weighted_sentiment), 4),
            'cross_source' => $risingCrossSource ? 1.0 : 0.0,
        ];
    }

    /**
     * Market-confirmation gate on the latest daily bar. Returns null when the
     * gate is disabled; otherwise an array with the gate inputs and verdict
     * (persisted in the signal breakdown for auditability).
     *
     * Tickers with no bars yet (first day on the radar) get an on-demand
     * Yahoo sync; if bars still can't be obtained the gate fails — a ticker
     * we can't price is a ticker we can't trade.
     *
     * @return array{passes: bool, close: ?float, volume_z: ?float, pre_return_3d: ?float, dollar_volume: ?float, bar_date: ?string, reason: ?string}|null
     */
    protected function marketGate(Ticker $ticker): ?array
    {
        $config = config('pennyhunt.signals.market_gate');

        if (! ($config['enabled'] ?? false)) {
            return null;
        }

        $bars = $this->latestBars($ticker->id);

        $stale = fn (Collection $b): bool => $b->isEmpty()
            || $b->first()->bucket_start->lt(now()->subDays((int) $config['max_bar_age_days']));

        if ($stale($bars)) {
            try {
                $this->marketData->syncDailyBars($ticker, CarbonImmutable::now()->subMonths(3), CarbonImmutable::now());
            } catch (\Throwable) {
                // offline / unknown symbol — fall through to the gate failure
            }

            $bars = $this->latestBars($ticker->id);
        }

        if ($stale($bars)) {
            return [
                'passes' => false,
                'close' => null,
                'volume_z' => null,
                'pre_return_3d' => null,
                'dollar_volume' => null,
                'bar_date' => null,
                'reason' => 'no_market_data',
            ];
        }

        $latest = $bars->first();
        $close = (float) $latest->close;

        // Volume z-score vs the trailing 30 bars (excluding the latest) —
        // same window as the Backtester even though latestBars now holds a
        // year of history for the technical features. With a short history
        // (new listing) the volume test is skipped and only the price cap
        // applies — price alone was still a 4.2x-lift gate.
        $trailing = $bars->slice(1)->take(30)->pluck('volume')->map(fn ($v) => (float) $v);
        $volumeZ = null;

        if ($trailing->count() >= 10) {
            $mean = $trailing->avg();
            $sd = sqrt($trailing->map(fn ($v) => ($v - $mean) ** 2)->sum() / ($trailing->count() - 1));

            if ($sd > 0) {
                $volumeZ = round(((float) $latest->volume - $mean) / $sd, 2);
            }
        }

        $passesPrice = $close <= (float) $config['max_entry_price'];
        $passesVolume = $volumeZ === null || $volumeZ >= (float) $config['min_volume_z'];

        // Extra features for the confidence model (same definitions as the
        // Backtester: 3-session pre-run and signal-day dollar volume).
        $close3Ago = $bars->count() > 3 ? (float) $bars[3]->close : null;
        $preReturn3d = $close3Ago !== null && $close3Ago > 0 ? round(($close - $close3Ago) / $close3Ago, 4) : null;

        // Technical features on the latest bar (same definitions as the
        // Backtester via TechnicalFeatures — bars ascending, signal = last).
        $ascending = $bars->reverse()->values()
            ->map(fn ($b): array => [
                'date' => $b->bucket_start->toDateString(),
                'open' => (float) $b->open,
                'high' => (float) $b->high,
                'low' => (float) $b->low,
                'close' => (float) $b->close,
                'volume' => (float) $b->volume,
            ])
            ->all();

        return [
            'passes' => $passesPrice && $passesVolume,
            'close' => round($close, 4),
            'volume_z' => $volumeZ,
            'pre_return_3d' => $preReturn3d,
            'dollar_volume' => round($close * (float) $latest->volume, 2),
            'bar_date' => $latest->bucket_start->toDateString(),
            'reason' => match (true) {
                ! $passesPrice => 'price_above_cap',
                ! $passesVolume => 'volume_not_confirmed',
                default => null,
            },
            ...TechnicalFeatures::compute($ascending, count($ascending) - 1),
        ];
    }

    /**
     * P(hit) from the active confidence model, using the exact feature
     * definitions the model was trained on (shared via ConfidenceTrainer).
     * Null when no model is active or market features are unavailable —
     * an honest "unknown" beats a made-up probability.
     *
     * @param  array{close: ?float, volume_z: ?float, pre_return_3d: ?float, dollar_volume: ?float}|null  $market
     * @param  array<string, mixed>  $intelFeatures
     */
    protected function confidence(?SignalModel $model, TickerMetric $metric, ?array $market, array $intelFeatures): ?float
    {
        if ($model === null || $market === null || $market['close'] === null) {
            return null;
        }

        return $model->predict(ConfidenceTrainer::features([
            'zscore' => (float) ($metric->zscore_mentions ?? 0.0),
            'volume_z' => $market['volume_z'],
            'sentiment' => $metric->weighted_sentiment !== null ? (float) $metric->weighted_sentiment : null,
            'unique_authors' => (int) $metric->unique_authors,
            'mentions' => (int) $metric->mention_count,
            'pre_return_3d' => $market['pre_return_3d'],
            'dollar_volume' => $market['dollar_volume'],
            // Technical features ride along inside $market (marketGate
            // computes them from the same bars); extra keys are ignored.
            ...$market,
            ...$intelFeatures,
        ]));
    }

    /** @return Collection<int, MarketBar> newest first; a year for the 52w-high technical */
    protected function latestBars(int $tickerId): Collection
    {
        return MarketBar::query()
            ->where('ticker_id', $tickerId)
            ->where('interval', '1d')
            ->orderByDesc('bucket_start')
            ->limit(260)
            ->get();
    }

    /**
     * Symbols whose mention counts are rising on independent aggregators
     * (latest snapshot vs 24h-ago figure reported by the provider).
     */
    protected function risingAggregatorSymbols(): Collection
    {
        return AggregatorSnapshot::query()
            ->where('captured_at', '>=', now()->subHours(3))
            ->whereNotNull('mentions_24h_ago')
            ->whereColumn('mentions', '>', 'mentions_24h_ago')
            ->distinct()
            ->pluck('symbol');
    }
}
