<?php

namespace App\Http\Controllers;

use App\Jobs\Ingestion\PullTwitterForTicker;
use App\Jobs\Ingestion\SyncCompanyProfile;
use App\Jobs\Ingestion\SyncTickerNews;
use App\Models\AggregatorSnapshot;
use App\Models\MarketBar;
use App\Models\RawPost;
use App\Models\Ticker;
use App\Models\TickerMetric;
use App\Models\TickerNews;
use App\Services\Features\MarketIntelligence;
use App\Services\MarketData\MarketClock;
use Inertia\Inertia;
use Inertia\Response;

class TickerController extends Controller
{
    public function show(string $symbol, MarketClock $clock): Response
    {
        $ticker = Ticker::query()->where('symbol', strtoupper($symbol))->firstOrFail();

        // Lazy company-profile refresh: only spend Polygon requests on pages
        // people actually open, at most once a week per ticker.
        $profile = $ticker->profile;

        if ($profile === null || $profile->synced_at->lt(now()->subDays(7))) {
            SyncCompanyProfile::dispatch($ticker->id);
        }

        // A human is looking: warm the news (6h cooldown) and the
        // X/Twitter tape (30m cooldown) in the background.
        SyncTickerNews::dispatchIfStale($ticker->id);
        PullTwitterForTicker::dispatchIfStale($ticker->id);

        $series = TickerMetric::query()
            ->where('ticker_id', $ticker->id)
            ->where('interval', '1h')
            ->where('bucket_start', '>=', now()->subDays(7))
            ->orderBy('bucket_start')
            ->get()
            ->map(fn (TickerMetric $m): array => [
                'bucket' => $m->bucket_start->toIso8601String(),
                'mentions' => $m->mention_count,
                'unique_authors' => $m->unique_authors,
                'sentiment' => $m->weighted_sentiment,
                'zscore' => $m->zscore_mentions,
            ]);

        $posts = RawPost::query()
            ->whereHas('mentions', fn ($q) => $q->where('ticker_id', $ticker->id))
            ->with([
                'source:id,key,name',
                'author:id,username,karma,pump_risk_score',
                'sentiment:id,raw_post_id,lexicon_score,llm_direction,llm_post_type,llm_pump_suspicion',
            ])
            ->orderByDesc('posted_at')
            ->limit(30)
            ->get()
            ->map(fn (RawPost $post): array => $this->postPayload($post));

        // X/Twitter confirmation feed: verified authors only, ranked by likes.
        // Empty until the Apify Twitter poller is enabled (paid plan).
        $tweets = RawPost::query()
            ->whereHas('source', fn ($q) => $q->where('type', 'twitter'))
            ->whereHas('mentions', fn ($q) => $q->where('ticker_id', $ticker->id))
            ->whereHas('author', fn ($q) => $q->where('stats->is_verified', true))
            // Like floor + LLM off-topic verdict (crypto token sharing the
            // $symbol, airdrop promos) keep the panel human and on-topic.
            ->where('score', '>=', (int) config('pennyhunt.apify.twitter.min_likes'))
            ->whereDoesntHave('sentiment', fn ($q) => $q->where('llm_off_topic', true))
            ->with([
                'source:id,key,name',
                'author:id,username,karma,pump_risk_score,stats',
                'sentiment:id,raw_post_id,lexicon_score,llm_direction,llm_post_type,llm_pump_suspicion',
            ])
            ->where('posted_at', '>=', now()->subDays(30))
            ->orderByDesc('score')
            ->limit(15)
            ->get()
            ->map(fn (RawPost $post): array => [
                ...$this->postPayload($post),
                'followers' => data_get($post->author?->stats, 'followers'),
                'retweets' => data_get($post->meta, 'retweets'),
            ]);

        // Full OHLC for the candlestick chart; 12 months so the range
        // switcher (1M..1Y) works client-side without refetching.
        $bars = MarketBar::query()
            ->where('ticker_id', $ticker->id)
            ->where('interval', '1d')
            ->where('bucket_start', '>=', now()->subMonths(12))
            ->orderBy('bucket_start')
            ->get()
            ->map(fn (MarketBar $bar): array => [
                'date' => $bar->bucket_start->toDateString(),
                'open' => (float) $bar->open,
                'high' => (float) $bar->high,
                'low' => (float) $bar->low,
                'close' => (float) $bar->close,
                'volume' => (float) $bar->volume,
            ]);

        $aggregatorHistory = AggregatorSnapshot::query()
            ->where('symbol', $ticker->symbol)
            ->where('captured_at', '>=', now()->subDays(7))
            ->orderBy('captured_at')
            ->get(['source_id', 'mentions', 'rank', 'sentiment_score', 'sentiment_label', 'captured_at']);

        // Dilution / short-flow snapshot, as-of today (same definitions as
        // the backtester's point-in-time features).
        $intel = MarketIntelligence::load([$ticker->id], now()->subDays(30)->toDateString(), now()->toDateString())
            ->features($ticker->id, now()->toDateString());

        $financials = $ticker->financials()
            ->where('timeframe', 'quarterly')
            ->orderByDesc('end_date')
            ->limit(6)
            ->get()
            ->map(fn ($f): array => [
                'end_date' => $f->end_date->toDateString(),
                'fiscal' => trim(($f->fiscal_period ?? '').' '.($f->fiscal_year ?? '')),
                'revenue' => $f->revenue,
                'net_income' => $f->net_income,
                'eps_basic' => $f->eps_basic,
                'operating_cash_flow' => $f->operating_cash_flow,
                'cash' => $f->cash,
                'total_assets' => $f->total_assets,
                'total_liabilities' => $f->total_liabilities,
                'equity' => $f->equity,
            ]);

        return Inertia::render('tickers/show', [
            'ticker' => $ticker->only(['id', 'symbol', 'name', 'exchange', 'tier', 'market_cap', 'last_price', 'is_ambiguous']),
            'profile' => $profile?->only([
                'description', 'sic_description', 'homepage_url', 'primary_exchange', 'city', 'state',
                'total_employees', 'list_date', 'market_cap', 'shares_outstanding',
            ]),
            'financials' => $financials,
            'intel' => $intel,
            'series' => $series,
            'bars' => $bars,
            'posts' => $posts,
            'tweets' => $tweets,
            'aggregatorHistory' => $aggregatorHistory,
            'signals' => $ticker->signals()->orderByDesc('fired_at')->limit(20)->get(),
            'marketStatus' => $clock->status(),
            'news' => TickerNews::query()
                ->where('ticker_id', $ticker->id)
                ->orderByDesc('published_at')
                ->limit(8)
                ->get()
                ->map(fn (TickerNews $n): array => [
                    'id' => $n->id,
                    'publisher' => $n->publisher,
                    'title' => $n->title,
                    'article_url' => $n->article_url,
                    'image_url' => $n->image_url,
                    'published_at' => $n->published_at->toIso8601String(),
                ]),
        ]);
    }

    /** @return array<string, mixed> */
    protected function postPayload(RawPost $post): array
    {
        return [
            'id' => $post->id,
            'kind' => $post->kind,
            'title' => $post->title,
            'body' => mb_substr($post->body ?? '', 0, 300),
            'permalink' => $post->permalink,
            'score' => $post->score,
            'posted_at' => $post->posted_at->toIso8601String(),
            'source' => $post->source->only(['key', 'name']),
            'author' => $post->author?->only(['username', 'karma', 'pump_risk_score']),
            'sentiment' => $post->sentiment?->only(['lexicon_score', 'llm_direction', 'llm_post_type', 'llm_pump_suspicion']),
        ];
    }
}
