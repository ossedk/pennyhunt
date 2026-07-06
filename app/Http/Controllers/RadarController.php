<?php

namespace App\Http\Controllers;

use App\Models\AggregatorSnapshot;
use App\Models\Signal;
use App\Models\SignalModel;
use App\Models\SignalTrade;
use App\Models\TickerMetric;
use App\Services\Features\MarketIntelligence;
use App\Services\Signals\SignalMath;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RadarController extends Controller
{
    public function index(Request $request): Response
    {
        $fireThreshold = (float) config('pennyhunt.signals.fire_threshold');

        // Leaderboard: tickers ranked by mention z-score in the most recent
        // 1h buckets, annotated with the live composite so "forming" setups
        // (close to the fire threshold) are visible before they fire.
        $rows = TickerMetric::query()
            ->where('interval', '1h')
            ->where('bucket_start', '>=', now()->subHours(3))
            ->with('ticker:id,symbol,name,exchange,last_price,is_ambiguous')
            ->orderByDesc('zscore_mentions')
            ->limit(200)
            ->get()
            ->unique('ticker_id')
            ->take(50)
            ->values()
            ->map(function (TickerMetric $m) use ($fireThreshold): array {
                $composite = SignalMath::composite([
                    'acceleration' => SignalMath::acceleration($m->zscore_mentions),
                    'breadth' => SignalMath::breadth($m->unique_authors, $m->mention_count),
                    'sentiment' => SignalMath::sentiment($m->weighted_sentiment),
                    'cross_source' => 0.0, // aggregator confirmation is applied at fire time
                ]);

                return [
                    'ticker_id' => $m->ticker_id,
                    'symbol' => $m->ticker->symbol,
                    'name' => $m->ticker->name,
                    'exchange' => $m->ticker->exchange,
                    'last_price' => $m->ticker->last_price,
                    'mention_count' => $m->mention_count,
                    'unique_authors' => $m->unique_authors,
                    'weighted_sentiment' => $m->weighted_sentiment,
                    'zscore_mentions' => $m->zscore_mentions,
                    'author_quality_avg' => $m->author_quality_avg,
                    'bucket_start' => $m->bucket_start->toIso8601String(),
                    'composite' => round($composite, 4),
                    'forming' => $composite >= $fireThreshold - 0.15 && $composite < $fireThreshold,
                ];
            });

        // Aggregator movers (works before Reddit credentials are configured)
        $latestSnapshotAt = AggregatorSnapshot::query()->max('captured_at');

        $aggregatorMovers = $latestSnapshotAt === null ? collect() : AggregatorSnapshot::query()
            ->where('captured_at', '>=', now()->parse($latestSnapshotAt)->subMinutes(5))
            ->whereNotNull('mentions_24h_ago')
            ->where('mentions_24h_ago', '>', 0)
            ->orderByDesc('captured_at')
            ->get()
            ->unique('symbol')
            ->map(fn (AggregatorSnapshot $s): array => [
                'symbol' => $s->symbol,
                'mentions' => $s->mentions,
                'mentions_24h_ago' => $s->mentions_24h_ago,
                'change_pct' => round((($s->mentions - $s->mentions_24h_ago) / max($s->mentions_24h_ago, 1)) * 100, 1),
                'rank' => $s->rank,
                'rank_24h_ago' => $s->rank_24h_ago,
                'sentiment_label' => $s->sentiment_label,
                'sentiment_score' => $s->sentiment_score,
            ])
            ->sortByDesc('change_pct')
            ->take(30)
            ->values();

        $recentSignals = Signal::query()
            ->with(['ticker:id,symbol,name', 'trade:id,signal_id,status,net_return,unrealized_return'])
            ->orderByDesc('fired_at')
            ->limit(10)
            ->get()
            ->map(fn (Signal $s): array => [
                'id' => $s->id,
                'symbol' => $s->ticker->symbol,
                'score' => $s->composite_score,
                'confidence' => $s->confidence,
                'state' => $s->state,
                'fired_at' => $s->fired_at->toIso8601String(),
                'trade_status' => $s->trade?->status,
            ]);

        // Open paper positions: the "am I about to get stopped?" rail.
        $positions = SignalTrade::query()
            ->where('book', 'legacy')
            ->whereIn('status', ['pending_entry', 'open'])
            ->with('ticker:id,symbol')
            ->orderByRaw("status = 'open' desc")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SignalTrade $t): array => [
                'id' => $t->id,
                'signal_id' => $t->signal_id,
                'symbol' => $t->ticker->symbol,
                'status' => $t->status,
                'entry_price' => $t->entry_price,
                'stop_price' => $t->stop_price,
                'last_quote' => $t->last_quote,
                'unrealized_return' => $t->unrealized_return,
                'holding_day' => $t->holdingDay(),
            ]);

        // Market regime strip: the same macro features the model sees.
        $today = now()->toDateString();
        $regimeFeatures = MarketIntelligence::load([], $today, $today)->features(0, $today);

        return Inertia::render('radar', [
            'leaderboard' => $rows,
            'aggregatorMovers' => $aggregatorMovers,
            'recentSignals' => $recentSignals,
            'positions' => $positions,
            'regime' => [
                'vix' => $regimeFeatures['vix'],
                'market_ret_5d' => $regimeFeatures['market_ret_5d'],
                'btc_ret_5d' => $regimeFeatures['btc_ret_5d'],
                'site_mention_z' => $regimeFeatures['site_mention_z'],
            ],
            'tradeTier' => SignalModel::active()?->metrics['trade_tier'] ?? null,
            'fireThreshold' => $fireThreshold,
            'freshness' => [
                'aggregator_at' => $latestSnapshotAt !== null ? now()->parse($latestSnapshotAt)->toIso8601String() : null,
                'metrics_at' => ($metricsAt = TickerMetric::query()->where('interval', '1h')->max('updated_at')) !== null
                    ? now()->parse($metricsAt)->toIso8601String()
                    : null,
            ],
        ]);
    }
}
