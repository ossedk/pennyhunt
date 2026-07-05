<?php

namespace App\Services\Metrics;

use App\Models\AuthorCall;
use App\Models\AuthorLeaderboard;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The voices leaderboard engine — finds reddit authors who are consistently
 * on the right side of the curve when a stock explodes.
 *
 * A call = an author's first non-bearish reddit post mentioning a ticker
 * (14-day dedupe), priced at the next session open like the backtester.
 * Grading against forward daily closes:
 *   win  — peak close within the horizon >= win_threshold (+30% default)
 *   loss — horizon close <= loss_threshold (-15% default)
 *   flat — everything in between
 * Ranking uses the 95% Wilson score lower bound on the win rate so a lucky
 * 3-for-3 never outranks a proven 20-for-40.
 */
class AuthorCallGrader
{
    /** Opens new calls from mentions that don't have one yet. Returns rows created. */
    public function openCalls(): int
    {
        $dedupeDays = (int) config('pennyhunt.voices.dedupe_days');

        // One candidate per (author, ticker, 14-day bin) inside the batch;
        // NOT EXISTS enforces the dedupe against already-open calls. Bearish
        // and LLM off-topic posts never open calls.
        return DB::affectingStatement(<<<'SQL'
            INSERT INTO author_calls (author_id, ticker_id, raw_post_id, called_at, outcome, created_at, updated_at)
            SELECT DISTINCT ON (c.author_id, c.ticker_id, c.bin)
                c.author_id, c.ticker_id, c.raw_post_id, c.posted_at, 'pending', NOW(), NOW()
            FROM (
                SELECT
                    p.author_id, m.ticker_id, p.id AS raw_post_id, m.posted_at,
                    date_bin((? || ' days')::interval, m.posted_at, '2020-01-01'::timestamptz) AS bin
                FROM post_ticker_mentions m
                JOIN raw_posts p ON p.id = m.raw_post_id
                JOIN sources s ON s.id = p.source_id
                LEFT JOIN post_sentiments ps ON ps.raw_post_id = p.id
                WHERE s.type = 'reddit'
                  AND p.author_id IS NOT NULL
                  AND COALESCE(ps.llm_off_topic, false) = false
                  AND COALESCE(ps.llm_direction, '') <> 'bearish'
            ) c
            WHERE NOT EXISTS (
                SELECT 1 FROM author_calls ac
                WHERE ac.author_id = c.author_id
                  AND ac.ticker_id = c.ticker_id
                  AND ac.called_at > c.posted_at - (? || ' days')::interval
                  AND ac.called_at <= c.posted_at
            )
            ORDER BY c.author_id, c.ticker_id, c.bin, c.posted_at
        SQL, [$dedupeDays, $dedupeDays]);
    }

    /**
     * Grades pending calls whose forward window has fully resolved.
     * Entry at next session open; window = the next N daily closes.
     * Returns rows graded.
     */
    public function gradeCalls(): int
    {
        $win = (float) config('pennyhunt.voices.win_threshold');
        $loss = (float) config('pennyhunt.voices.loss_threshold');
        $horizon = (int) config('pennyhunt.voices.horizon_sessions');

        $graded = DB::affectingStatement(<<<'SQL'
            UPDATE author_calls ac SET
                entry_date = g.entry_date,
                entry_price = g.entry_price,
                peak_return = g.peak_return,
                day5_return = g.day5_return,
                runup_3d = g.runup_3d,
                outcome = CASE
                    WHEN g.peak_return >= ? THEN 'win'
                    WHEN g.day5_return <= ? THEN 'loss'
                    ELSE 'flat' END,
                graded_at = NOW(),
                updated_at = NOW()
            FROM (
                SELECT c.id,
                       e.entry_date, e.entry_price,
                       f.peak_close / e.entry_price - 1 AS peak_return,
                       f.day5_close / e.entry_price - 1 AS day5_return,
                       CASE WHEN r.ref_close > 0 THEN e.entry_price / r.ref_close - 1 END AS runup_3d
                FROM author_calls c
                JOIN LATERAL (
                    SELECT b.bucket_start::date AS entry_date, b.open AS entry_price
                    FROM market_bars b
                    WHERE b.ticker_id = c.ticker_id AND b.interval = '1d'
                      AND b.bucket_start::date > c.called_at::date
                    ORDER BY b.bucket_start
                    LIMIT 1
                ) e ON e.entry_price > 0
                JOIN LATERAL (
                    SELECT MAX(w.close) AS peak_close,
                           (ARRAY_AGG(w.close ORDER BY w.bucket_start))[?] AS day5_close,
                           COUNT(*) AS n
                    FROM (
                        SELECT close, bucket_start FROM market_bars
                        WHERE ticker_id = c.ticker_id AND interval = '1d'
                          AND bucket_start::date > e.entry_date
                        ORDER BY bucket_start
                        LIMIT ?
                    ) w
                ) f ON f.n = ?
                LEFT JOIN LATERAL (
                    SELECT close AS ref_close FROM market_bars
                    WHERE ticker_id = c.ticker_id AND interval = '1d'
                      AND bucket_start::date < e.entry_date
                    ORDER BY bucket_start DESC
                    OFFSET 2 LIMIT 1
                ) r ON true
                WHERE c.outcome = 'pending'
            ) g
            WHERE ac.id = g.id
        SQL, [$win, $loss, $horizon, $horizon, $horizon]);

        // Calls with no bars after 30 days will never grade — stop rescanning.
        DB::table('author_calls')
            ->where('outcome', 'pending')
            ->where('called_at', '<', now()->subDays(30))
            ->update(['outcome' => 'unpriceable', 'updated_at' => now()]);

        return $graded;
    }

    /** Builds the ranked weekly snapshot. Returns rows written. */
    public function snapshot(?CarbonInterface $weekStart = null): int
    {
        $weekStart ??= now()->startOfWeek();
        $minCalls = (int) config('pennyhunt.voices.min_calls');
        $size = (int) config('pennyhunt.voices.leaderboard_size');

        $rows = DB::select(<<<'SQL'
            SELECT author_id,
                   COUNT(*) AS calls,
                   SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) AS wins,
                   SUM(CASE WHEN outcome = 'flat' THEN 1 ELSE 0 END) AS flats,
                   SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) AS losses,
                   AVG(peak_return) AS avg_peak,
                   MAX(peak_return) AS best_peak
            FROM author_calls
            WHERE outcome IN ('win', 'flat', 'loss')
            GROUP BY author_id
            HAVING COUNT(*) >= ?
        SQL, [$minCalls]);

        $ranked = collect($rows)
            ->map(function (object $r): array {
                $n = (int) $r->calls;
                $wins = (int) $r->wins;

                return [
                    'author_id' => (int) $r->author_id,
                    'calls' => $n,
                    'wins' => $wins,
                    'flats' => (int) $r->flats,
                    'losses' => (int) $r->losses,
                    'hit_rate' => round($wins / $n, 4),
                    'wilson_lb' => round($this->wilsonLowerBound($wins, $n), 4),
                    'avg_peak_return' => $r->avg_peak !== null ? round((float) $r->avg_peak, 4) : null,
                    'best_peak_return' => $r->best_peak !== null ? round((float) $r->best_peak, 4) : null,
                ];
            })
            ->sortByDesc('wilson_lb')
            ->take($size)
            ->values();

        AuthorLeaderboard::query()->whereDate('week_start', $weekStart->toDateString())->delete();

        foreach ($ranked as $i => $row) {
            AuthorLeaderboard::create([
                ...$row,
                'week_start' => $weekStart->toDateString(),
                'rank' => $i + 1,
                'best_call' => $this->bestCall($row['author_id']),
                'top_tickers' => $this->topTickers($row['author_id']),
                'recent_calls' => $this->recentCalls($row['author_id']),
            ]);
        }

        return $ranked->count();
    }

    /**
     * 95% Wilson score lower bound on the win rate — the standard fix for
     * ranking by proportion with small samples.
     */
    protected function wilsonLowerBound(int $successes, int $trials): float
    {
        if ($trials === 0) {
            return 0.0;
        }

        $z = 1.96;
        $p = $successes / $trials;

        return max(0.0,
            ($p + $z ** 2 / (2 * $trials) - $z * sqrt(($p * (1 - $p) + $z ** 2 / (4 * $trials)) / $trials))
            / (1 + $z ** 2 / $trials)
        );
    }

    /** @return array{symbol: string, peak_return: float, called_at: string}|null */
    protected function bestCall(int $authorId): ?array
    {
        $call = AuthorCall::query()
            ->where('author_id', $authorId)
            ->where('outcome', 'win')
            ->orderByDesc('peak_return')
            ->with('ticker:id,symbol')
            ->first();

        if ($call === null || $call->ticker === null) {
            return null;
        }

        return [
            'symbol' => $call->ticker->symbol,
            'peak_return' => (float) $call->peak_return,
            'called_at' => $call->called_at->toDateString(),
        ];
    }

    /** @return list<array{symbol: string, calls: int, wins: int}> */
    protected function topTickers(int $authorId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT t.symbol, COUNT(*) AS calls, SUM(CASE WHEN c.outcome = 'win' THEN 1 ELSE 0 END) AS wins
            FROM author_calls c
            JOIN tickers t ON t.id = c.ticker_id
            WHERE c.author_id = ? AND c.outcome IN ('win', 'flat', 'loss')
            GROUP BY t.symbol
            ORDER BY COUNT(*) DESC, SUM(CASE WHEN c.outcome = 'win' THEN 1 ELSE 0 END) DESC
            LIMIT 5
        SQL, [$authorId]);

        return array_map(fn (object $r): array => [
            'symbol' => $r->symbol,
            'calls' => (int) $r->calls,
            'wins' => (int) $r->wins,
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    protected function recentCalls(int $authorId): array
    {
        return AuthorCall::query()
            ->where('author_id', $authorId)
            ->whereIn('outcome', ['win', 'flat', 'loss'])
            ->orderByDesc('called_at')
            ->limit(8)
            ->with(['ticker:id,symbol', 'rawPost:id,permalink,title'])
            ->get()
            ->map(fn (AuthorCall $c): array => [
                'symbol' => $c->ticker?->symbol,
                'called_at' => $c->called_at->toDateString(),
                'outcome' => $c->outcome,
                'peak_return' => $c->peak_return,
                'day5_return' => $c->day5_return,
                'runup_3d' => $c->runup_3d,
                'permalink' => $c->rawPost?->permalink,
            ])
            ->all();
    }
}
