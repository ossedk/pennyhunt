<?php

namespace App\Http\Controllers;

use App\Jobs\Nlp\GenerateMarketBrief;
use App\Models\MarketBrief;
use App\Models\RawPost;
use App\Models\Signal;
use App\Models\SignalTrade;
use App\Models\TickerNews;
use App\Services\Features\MarketIntelligence;
use App\Services\MarketData\MarketClock;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Desk — the landing page. A 60-second orientation: LLM market brief,
 * regime + session state, the tape's biggest moves among socially-active
 * names, the crowd's loudest tickers, top hyped posts, open risk, and the
 * news that matters for trending names.
 */
class DashboardController extends Controller
{
    public function index(MarketClock $clock): Response
    {
        $brief = MarketBrief::current();

        // Stale or missing → regenerate in the background; the page shows
        // the previous brief (or a skeleton) meanwhile.
        if ($brief === null || $brief->generated_at->lt(now()->subMinutes(GenerateMarketBrief::FRESH_MINUTES))) {
            GenerateMarketBrief::dispatch();
        }

        $today = now()->toDateString();
        $regime = MarketIntelligence::load([], $today, $today)->features(0, $today);

        return Inertia::render('dashboard', [
            'brief' => $brief === null ? null : [
                ...$brief->brief,
                'generated_at' => $brief->generated_at->toIso8601String(),
            ],
            'marketStatus' => $clock->status(),
            'regime' => [
                'vix' => $regime['vix'],
                'market_ret_5d' => $regime['market_ret_5d'],
                'btc_ret_5d' => $regime['btc_ret_5d'],
                'site_mention_z' => $regime['site_mention_z'],
                'smallcap_rel_20d' => $regime['smallcap_rel_20d'],
                'xbi_ret_5d' => $regime['xbi_ret_5d'],
            ],
            'movers' => $this->movers(),
            'loudest' => $this->loudest(),
            'hypedPosts' => $this->hypedPosts(),
            'positions' => $this->positions(),
            'recentSignals' => $this->recentSignals(),
            'news' => $this->topNews(),
            'moonshotRadar' => $this->moonshotRadar(),
            'recentHalts' => $this->recentHalts(),
        ]);
    }

    /**
     * What the moonshot head is watching: the strongest score per ticker
     * over the last 24h of engine scans — fires, near-misses and the gate
     * that blocked each. Forward visibility instead of after-the-fact.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function moonshotRadar(): array
    {
        // Portable best-score-per-ticker (blocked_by approximated by MAX —
        // good enough for a radar chip).
        return DB::table('moonshot_scans as s')
            ->join('tickers as t', 't.id', '=', 's.ticker_id')
            ->where('s.scanned_at', '>=', now()->subDay())
            ->groupBy('s.ticker_id', 't.symbol', 't.name')
            ->selectRaw('t.symbol, t.name, MAX(s.p) as p, MAX(CASE WHEN s.fired THEN 1 ELSE 0 END) as fired, MAX(s.blocked_by) as blocked_by, MAX(s.scanned_at) as scanned_at')
            ->orderByDesc('p')
            ->limit(12)
            ->get()
            ->map(fn (object $r): array => [
                'symbol' => $r->symbol,
                'name' => $r->name,
                'p' => (float) $r->p,
                'fired' => (bool) $r->fired,
                'blocked_by' => $r->blocked_by,
                'scanned_at' => (string) $r->scanned_at,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function recentHalts(): array
    {
        return DB::table('trade_halts as h')
            ->leftJoin('tickers as t', 't.id', '=', 'h.ticker_id')
            ->where('h.halted_at', '>=', now()->subDay())
            ->orderByDesc('h.halted_at')
            ->limit(10)
            ->get(['h.symbol', 't.name', 'h.halted_at', 'h.resumed_at', 'h.reason'])
            ->map(fn (object $r): array => [
                'symbol' => $r->symbol,
                'name' => $r->name,
                'halted_at' => (string) $r->halted_at,
                'resumed_at' => $r->resumed_at !== null ? (string) $r->resumed_at : null,
                'reason' => $r->reason,
            ])
            ->all();
    }

    /**
     * Biggest last-session moves among tickers with social attention in
     * the last 48h — "what actually moved", not just what was loud.
     *
     * @return array<int, object>
     */
    protected function movers(): array
    {
        $rows = DB::select(<<<'SQL'
            WITH active AS (
                SELECT m.ticker_id, COUNT(*) AS mentions
                FROM post_ticker_mentions m
                WHERE m.posted_at >= ?
                GROUP BY m.ticker_id
            ),
            latest AS (
                SELECT b.ticker_id, b.close, b.volume,
                       LAG(b.close) OVER (PARTITION BY b.ticker_id ORDER BY b.bucket_start) AS prev_close,
                       ROW_NUMBER() OVER (PARTITION BY b.ticker_id ORDER BY b.bucket_start DESC) AS rn
                FROM market_bars b
                JOIN active a ON a.ticker_id = b.ticker_id
                WHERE b.interval = '1d' AND b.bucket_start >= ?
            )
            SELECT t.symbol, t.name, a.mentions, l.close, l.prev_close, l.volume
            FROM latest l
            JOIN tickers t ON t.id = l.ticker_id AND t.is_active
            JOIN active a ON a.ticker_id = l.ticker_id
            WHERE l.rn = 1 AND l.prev_close IS NOT NULL AND l.prev_close > 0
            ORDER BY ABS(l.close / l.prev_close - 1) DESC
            LIMIT 8
        SQL, [now()->subHours(48), now()->subDays(7)]);

        return array_map(fn (object $r): array => [
            'symbol' => $r->symbol,
            'name' => $r->name,
            'mentions' => (int) $r->mentions,
            'day_return' => round((float) $r->close / (float) $r->prev_close - 1, 4),
            'close' => round((float) $r->close, 4),
            'volume' => (int) $r->volume,
        ], $rows);
    }

    /**
     * Loudest tickers in the last 24h (Reddit-only, per AnalyticsGate).
     *
     * @return array<int, object>
     */
    protected function loudest(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT t.symbol, t.name, t.last_price,
                   COUNT(*) AS mentions,
                   COUNT(DISTINCT p.author_id) AS authors,
                   AVG(s.lexicon_score) AS sentiment
            FROM post_ticker_mentions m
            JOIN raw_posts p ON p.id = m.raw_post_id
            JOIN sources src ON src.id = p.source_id AND src.type <> 'twitter'
            LEFT JOIN post_sentiments s ON s.raw_post_id = p.id
            JOIN tickers t ON t.id = m.ticker_id AND t.is_active
            WHERE m.posted_at >= ?
            GROUP BY t.id, t.symbol, t.name, t.last_price
            ORDER BY COUNT(*) DESC
            LIMIT 8
        SQL, [now()->subHours(24)]);

        return array_map(fn (object $r): array => [
            'symbol' => $r->symbol,
            'name' => $r->name,
            'last_price' => $r->last_price !== null ? (float) $r->last_price : null,
            'mentions' => (int) $r->mentions,
            'authors' => (int) $r->authors,
            'sentiment' => $r->sentiment !== null ? round((float) $r->sentiment, 3) : null,
        ], $rows);
    }

    /**
     * The posts driving today's attention: highest-engagement ticker
     * posts of the last 24h, LLM-labelled, off-topic excluded.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function hypedPosts(): array
    {
        return RawPost::query()
            ->whereHas('mentions')
            ->where('posted_at', '>=', now()->subHours(24))
            ->whereDoesntHave('sentiment', fn ($q) => $q->where('llm_off_topic', true))
            ->with([
                'source:id,key,name',
                'author:id,username,karma,pump_risk_score',
                'sentiment:id,raw_post_id,lexicon_score,llm_direction,llm_post_type,llm_pump_suspicion',
                'mentions.ticker:id,symbol',
            ])
            ->orderByDesc('score')
            ->limit(6)
            ->get()
            ->map(fn (RawPost $post): array => [
                'id' => $post->id,
                'title' => $post->title,
                'body' => mb_substr($post->body ?? '', 0, 200),
                'permalink' => $post->permalink,
                'score' => $post->score,
                'posted_at' => $post->posted_at->toIso8601String(),
                'source' => $post->source->only(['key', 'name']),
                'author' => $post->author?->only(['username', 'pump_risk_score']),
                'symbols' => $post->mentions->map(fn ($m) => $m->ticker->symbol)->unique()->values()->take(4),
                'sentiment' => $post->sentiment?->only(['lexicon_score', 'llm_direction', 'llm_post_type', 'llm_pump_suspicion']),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function positions(): array
    {
        return SignalTrade::query()
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
                'unrealized_return' => $t->unrealized_return,
                'holding_day' => $t->holdingDay(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function recentSignals(): array
    {
        return Signal::query()
            ->with('ticker:id,symbol')
            ->orderByDesc('fired_at')
            ->limit(5)
            ->get()
            ->map(fn (Signal $s): array => [
                'id' => $s->id,
                'symbol' => $s->ticker->symbol,
                'confidence' => $s->confidence,
                'fired_at' => $s->fired_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Top hyped news: fresh articles for the tickers with the most 24h
     * mentions — our attention data ranks the wire.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function topNews(): array
    {
        return TickerNews::query()
            ->with('ticker:id,symbol')
            ->joinSub(
                DB::table('post_ticker_mentions')
                    ->selectRaw('ticker_id, COUNT(*) AS mentions_24h')
                    ->where('posted_at', '>=', now()->subHours(24))
                    ->groupBy('ticker_id'),
                'buzz',
                'buzz.ticker_id',
                'ticker_news.ticker_id',
            )
            ->where('published_at', '>=', now()->subHours(48))
            ->orderByDesc('buzz.mentions_24h')
            ->orderByDesc('published_at')
            ->limit(6)
            ->get(['ticker_news.*', 'buzz.mentions_24h'])
            ->unique(fn (TickerNews $n) => $n->ticker_id.'|'.mb_substr($n->title, 0, 40))
            ->take(5)
            ->map(fn (TickerNews $n): array => [
                'id' => $n->id,
                'symbol' => $n->ticker->symbol,
                'publisher' => $n->publisher,
                'title' => $n->title,
                'article_url' => $n->article_url,
                'image_url' => $n->image_url,
                'published_at' => $n->published_at->toIso8601String(),
                'mentions_24h' => (int) $n->mentions_24h,
            ])
            ->values()
            ->all();
    }
}
