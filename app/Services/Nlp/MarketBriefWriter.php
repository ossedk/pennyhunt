<?php

namespace App\Services\Nlp;

use App\Models\MarketBrief;
use App\Models\Signal;
use App\Models\SignalTrade;
use App\Models\TickerNews;
use App\Services\Features\MarketIntelligence;
use App\Services\MarketData\MarketClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Writes the Desk's market brief: a short, concrete, regime-aware read of
 * what the crowd and the tape are doing right now, and what deserves a
 * look. The LLM is given a CLOSED WORLD — a compact JSON context built
 * entirely from our own aggregates — and must only reference entities in
 * it. Output is structured JSON (headline, body, watch items bound to
 * symbols, risk flags), validated before storage; the UI only links
 * symbols it can resolve in our universe.
 */
class MarketBriefWriter
{
    protected const SYSTEM_PROMPT = <<<'PROMPT'
You are the morning-desk analyst for a penny-stock social-sentiment trading platform. You receive a JSON context with (1) market regime data, (2) the most-mentioned tickers on retail forums with mention z-scores and sentiment, (3) the biggest price movers among socially-active tickers, (4) recent model signals and open paper positions, and (5) recent news headlines.

Write a brief a trader reads in 60 seconds. Rules:
- ONLY reference tickers, numbers and headlines present in the context. Never invent facts, prices or tickers.
- Be concrete: name symbols, cite the numbers given (returns, z-scores, VIX).
- All return fields are decimal fractions: day_return 0.03 means +3%, -0.283 means -28.3%. Always convert to percentages in prose and never show the raw fraction.
- Penny-stock chatter is full of pumps: where mention spikes lack price/volume confirmation or sentiment looks coordinated, say so.
- Tone: sober desk note, no hype, no filler, no disclaimers.

Return ONLY JSON:
{
 "headline": "one line, <=90 chars, the single most important thing right now",
 "body": ["2-3 short paragraphs: regime read; what the crowd is loud about and whether the tape confirms; what our model/positions say"],
 "watch": [{"symbol": "TICK", "reason": "<=120 chars, concrete reason to look"}],  // 2-4 items, symbols from context only
 "risks": ["0-3 one-liners: pump suspicion, regime warnings, thin-data caveats"]
}
PROMPT;

    public function __construct(
        protected MarketClock $clock,
    ) {}

    public function enabled(): bool
    {
        return filled(config('pennyhunt.llm.openai_api_key'));
    }

    /** Generates and stores a brief. Returns null when disabled or the LLM output is unusable. */
    public function write(): ?MarketBrief
    {
        if (! $this->enabled()) {
            return null;
        }

        $context = $this->buildContext();

        $raw = $this->complete(json_encode($context, JSON_UNESCAPED_SLASHES));
        $brief = $this->validate($raw, $context);

        if ($brief === null) {
            return null;
        }

        return MarketBrief::query()->create([
            'model' => (string) config('pennyhunt.llm.openai_model'),
            'brief' => $brief,
            'context' => $context,
            'generated_at' => now(),
        ]);
    }

    /**
     * The closed world: everything the LLM may talk about.
     *
     * @return array<string, mixed>
     */
    public function buildContext(): array
    {
        $today = now()->toDateString();
        $regime = MarketIntelligence::load([], $today, $today)->features(0, $today);

        // Most-mentioned tickers (24h) with author breadth and sentiment.
        $loudest = array_map(fn (object $r): array => [
            'symbol' => $r->symbol,
            'mentions' => (int) $r->mentions,
            'authors' => (int) $r->authors,
            'sentiment' => $r->sentiment !== null ? round((float) $r->sentiment, 2) : null,
        ], DB::select(<<<'SQL'
            SELECT t.symbol, COUNT(*) AS mentions, COUNT(DISTINCT p.author_id) AS authors,
                   AVG(s.lexicon_score) AS sentiment
            FROM post_ticker_mentions m
            JOIN raw_posts p ON p.id = m.raw_post_id
            JOIN sources src ON src.id = p.source_id AND src.type <> 'twitter'
            LEFT JOIN post_sentiments s ON s.raw_post_id = p.id
            JOIN tickers t ON t.id = m.ticker_id AND t.is_active
            WHERE m.posted_at >= ?
            GROUP BY t.symbol
            ORDER BY COUNT(*) DESC
            LIMIT 10
        SQL, [now()->subHours(24)]));

        // Price movers among tickers with recent social attention.
        $movers = array_map(fn (object $r): array => [
            'symbol' => $r->symbol,
            'day_return' => round((float) $r->close / (float) $r->prev_close - 1, 3),
            'close' => round((float) $r->close, 4),
        ], DB::select(<<<'SQL'
            WITH active AS (
                SELECT DISTINCT m.ticker_id
                FROM post_ticker_mentions m
                WHERE m.posted_at >= ?
            ),
            latest AS (
                SELECT b.ticker_id, b.close, b.bucket_start,
                       LAG(b.close) OVER (PARTITION BY b.ticker_id ORDER BY b.bucket_start) AS prev_close,
                       ROW_NUMBER() OVER (PARTITION BY b.ticker_id ORDER BY b.bucket_start DESC) AS rn
                FROM market_bars b
                JOIN active a ON a.ticker_id = b.ticker_id
                WHERE b.interval = '1d' AND b.bucket_start >= ?
            )
            SELECT t.symbol, l.close, l.prev_close
            FROM latest l
            JOIN tickers t ON t.id = l.ticker_id
            WHERE l.rn = 1 AND l.prev_close IS NOT NULL AND l.prev_close > 0
            ORDER BY ABS(l.close / l.prev_close - 1) DESC
            LIMIT 8
        SQL, [now()->subHours(48), now()->subDays(7)]));

        $signals = Signal::query()
            ->with('ticker:id,symbol')
            ->where('fired_at', '>=', now()->subHours(48))
            ->orderByDesc('fired_at')
            ->limit(5)
            ->get()
            ->map(fn (Signal $s): array => [
                'symbol' => $s->ticker->symbol,
                'confidence' => $s->confidence,
                'fired_at' => $s->fired_at->toDateTimeString(),
            ])
            ->all();

        $positions = SignalTrade::query()
            ->whereIn('status', ['pending_entry', 'open'])
            ->with('ticker:id,symbol')
            ->get()
            ->map(fn (SignalTrade $t): array => [
                'symbol' => $t->ticker->symbol,
                'status' => $t->status,
                'unrealized_return' => $t->unrealized_return,
                'holding_day' => $t->holdingDay(),
            ])
            ->all();

        $headlines = TickerNews::query()
            ->with('ticker:id,symbol')
            ->where('published_at', '>=', now()->subHours(36))
            ->orderByDesc('published_at')
            ->limit(10)
            ->get()
            ->map(fn (TickerNews $n): array => [
                'symbol' => $n->ticker->symbol,
                'title' => mb_substr($n->title, 0, 140),
                'publisher' => $n->publisher,
            ])
            ->all();

        return [
            'as_of' => now()->toIso8601String(),
            'market' => $this->clock->status()['status'],
            'regime' => [
                'vix' => $regime['vix'],
                'small_cap_ret_5d' => $regime['market_ret_5d'],
                'btc_ret_5d' => $regime['btc_ret_5d'],
                'site_buzz_z' => $regime['site_mention_z'],
            ],
            'loudest_tickers_24h' => $loudest,
            'biggest_movers' => $movers,
            'recent_signals' => $signals,
            'open_positions' => $positions,
            'headlines' => $headlines,
        ];
    }

    /**
     * Parse + sanity-check the LLM output; drop watch items whose symbol
     * is not part of the context (closed-world enforcement).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    protected function validate(?string $raw, array $context): ?array
    {
        if ($raw === null) {
            return null;
        }

        $json = json_decode($raw, true);

        if (! is_array($json) || blank($json['headline'] ?? null) || ! is_array($json['body'] ?? null)) {
            return null;
        }

        $known = collect([
            ...array_column((array) $context['loudest_tickers_24h'], 'symbol'),
            ...array_column((array) $context['biggest_movers'], 'symbol'),
            ...array_column($context['recent_signals'], 'symbol'),
            ...array_column($context['open_positions'], 'symbol'),
            ...array_column($context['headlines'], 'symbol'),
        ])->map(fn ($s) => strtoupper((string) $s))->flip();

        $watch = collect($json['watch'] ?? [])
            ->filter(fn ($w): bool => is_array($w)
                && isset($w['symbol'], $w['reason'])
                && $known->has(strtoupper((string) $w['symbol'])))
            ->map(fn (array $w): array => [
                'symbol' => strtoupper((string) $w['symbol']),
                'reason' => mb_substr((string) $w['reason'], 0, 160),
            ])
            ->values()
            ->all();

        return [
            'headline' => mb_substr((string) $json['headline'], 0, 120),
            'body' => array_values(array_map(fn ($p): string => (string) $p, array_slice($json['body'], 0, 4))),
            'watch' => $watch,
            'risks' => array_values(array_map(
                fn ($r): string => mb_substr((string) $r, 0, 200),
                array_slice((array) ($json['risks'] ?? []), 0, 4),
            )),
        ];
    }

    protected function complete(string $context): ?string
    {
        $response = Http::timeout(90)
            ->retry(2, 3000, throw: false)
            ->withToken((string) config('pennyhunt.llm.openai_api_key'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('pennyhunt.llm.openai_model'),
                'reasoning_effort' => 'low',
                'max_completion_tokens' => 2500,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $context],
                ],
            ]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('choices.0.message.content');
    }
}
