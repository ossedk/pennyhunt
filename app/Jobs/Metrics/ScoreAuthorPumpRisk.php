<?php

namespace App\Jobs\Metrics;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Nightly author analysis: heuristic pump-risk score in 0..1 that discounts
 * an author's sentiment contribution (see Author::qualityWeight and the
 * matching SQL in BuildTickerMetrics).
 *
 * Components over the author's last 30 days of posts (each 0..1):
 *  - concentration: share of the author's ticker mentions on their single
 *    most-mentioned ticker (1.0 = only ever talks about one ticker)
 *  - burst: posting frequency, saturating at 10 posts/day
 *  - newness: 1 for brand-new accounts, 0 at >= 180 days old
 *
 * risk = 0.5*concentration + 0.3*burst + 0.2*newness, only applied to
 * authors with >= 3 recent posts (small samples say nothing).
 */
class ScoreAuthorPumpRisk implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('metrics');
    }

    public function handle(): void
    {
        DB::statement(<<<'SQL'
            WITH recent AS (
                SELECT
                    p.author_id,
                    COUNT(DISTINCT p.id) AS posts,
                    COUNT(m.id) AS mentions,
                    MAX(cnt.top_mentions) AS top_mentions,
                    EXTRACT(EPOCH FROM (MAX(p.posted_at) - MIN(p.posted_at))) / 86400.0 AS span_days
                FROM raw_posts p
                LEFT JOIN post_ticker_mentions m ON m.raw_post_id = p.id
                LEFT JOIN LATERAL (
                    SELECT COUNT(*) AS top_mentions
                    FROM post_ticker_mentions m2
                    JOIN raw_posts p2 ON p2.id = m2.raw_post_id
                    WHERE p2.author_id = p.author_id
                      AND p2.posted_at >= NOW() - INTERVAL '30 days'
                    GROUP BY m2.ticker_id
                    ORDER BY COUNT(*) DESC
                    LIMIT 1
                ) cnt ON TRUE
                WHERE p.author_id IS NOT NULL
                  AND p.posted_at >= NOW() - INTERVAL '30 days'
                GROUP BY p.author_id
                HAVING COUNT(DISTINCT p.id) >= 3
            )
            UPDATE authors a SET
                pump_risk_score = LEAST(1.0, GREATEST(0.0,
                    0.5 * CASE WHEN r.mentions > 0
                               THEN r.top_mentions::float / r.mentions
                               ELSE 0 END
                    + 0.3 * LEAST(r.posts / GREATEST(r.span_days, 1.0) / 10.0, 1.0)
                    + 0.2 * CASE WHEN a.account_created_at IS NULL THEN 0.5
                                 ELSE GREATEST(0, 1 - EXTRACT(EPOCH FROM (NOW() - a.account_created_at)) / 86400.0 / 180.0)
                            END
                )),
                updated_at = NOW()
            FROM recent r
            WHERE a.id = r.author_id
        SQL);
    }
}
