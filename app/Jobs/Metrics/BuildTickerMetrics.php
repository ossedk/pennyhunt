<?php

namespace App\Jobs\Metrics;

use App\Support\AnalyticsGate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Builds per-ticker rollup buckets (mention counts, author breadth, sentiment)
 * from post_ticker_mentions and then refreshes z-scores against each ticker's
 * own trailing baseline.
 *
 * Author quality weight (SQL mirror of Author::qualityWeight):
 *   LEAST(log(karma+1)/5, 1) * 0.5 + LEAST(age_days/730, 1) * 0.5, floored at 0.05,
 *   multiplied by (1 - pump_risk_score).
 */
class BuildTickerMetrics implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $interval, // 5m | 1h | 1d
        public ?string $lookbackOverride = null, // e.g. '60 days' for repair rebuilds
    ) {
        $this->onQueue('metrics');
    }

    public function handle(): void
    {
        [$binSize, $lookback] = match ($this->interval) {
            '5m' => ['5 minutes', '3 hours'],
            '1h' => ['1 hour', '48 hours'],
            '1d' => ['1 day', '14 days'],
            default => throw new \InvalidArgumentException("Unknown interval [{$this->interval}]"),
        };

        $lookback = $this->lookbackOverride ?? $lookback;

        $gate = AnalyticsGate::sourceJoin('p');

        DB::statement(<<<SQL
            INSERT INTO ticker_metrics
                (ticker_id, interval, bucket_start, mention_count, unique_authors,
                 avg_sentiment, weighted_sentiment, author_quality_avg, created_at, updated_at)
            SELECT
                m.ticker_id,
                ? AS interval,
                date_bin(?::interval, m.posted_at, '2020-01-01'::timestamptz) AS bucket_start,
                COUNT(*) AS mention_count,
                COUNT(DISTINCT p.author_id) AS unique_authors,
                AVG(s.lexicon_score) AS avg_sentiment,
                CASE WHEN SUM(q.weight) > 0
                     THEN SUM(s.lexicon_score * q.weight) / SUM(q.weight)
                     ELSE NULL END AS weighted_sentiment,
                AVG(q.weight) AS author_quality_avg,
                NOW(), NOW()
            FROM post_ticker_mentions m
            JOIN raw_posts p ON p.id = m.raw_post_id
            {$gate}
            LEFT JOIN post_sentiments s ON s.raw_post_id = p.id
            LEFT JOIN authors a ON a.id = p.author_id
            CROSS JOIN LATERAL (
                SELECT GREATEST(
                    (
                        LEAST(LOG(GREATEST(COALESCE(a.karma, 0), 1) + 1) / 5.0, 1.0) * 0.5
                        + LEAST(COALESCE(EXTRACT(EPOCH FROM (NOW() - a.account_created_at)) / 86400.0, 0) / 730.0, 1.0) * 0.5
                    ) * (1 - COALESCE(a.pump_risk_score, 0)),
                    0.05
                ) AS weight
            ) q
            WHERE m.posted_at >= NOW() - ?::interval
            GROUP BY m.ticker_id, bucket_start
            ON CONFLICT (ticker_id, interval, bucket_start) DO UPDATE SET
                mention_count = EXCLUDED.mention_count,
                unique_authors = EXCLUDED.unique_authors,
                avg_sentiment = EXCLUDED.avg_sentiment,
                weighted_sentiment = EXCLUDED.weighted_sentiment,
                author_quality_avg = EXCLUDED.author_quality_avg,
                updated_at = NOW()
        SQL, [$this->interval, $binSize, $lookback]);

        $this->refreshZScores($lookback);
    }

    /**
     * Z-scores vs the ticker's own trailing baseline (config: signals.baseline_days).
     * Only recent buckets (inside the lookback) are refreshed.
     */
    protected function refreshZScores(string $lookback): void
    {
        $baselineDays = (int) config('pennyhunt.signals.baseline_days');

        DB::statement(<<<'SQL'
            WITH baseline AS (
                SELECT
                    ticker_id,
                    AVG(mention_count) AS mean_mentions,
                    STDDEV_SAMP(mention_count) AS sd_mentions,
                    AVG(weighted_sentiment) AS mean_sentiment,
                    STDDEV_SAMP(weighted_sentiment) AS sd_sentiment
                FROM ticker_metrics
                WHERE interval = ?
                  AND bucket_start >= NOW() - (? || ' days')::interval
                GROUP BY ticker_id
            )
            UPDATE ticker_metrics tm SET
                zscore_mentions = CASE
                    WHEN b.sd_mentions IS NULL OR b.sd_mentions = 0 THEN NULL
                    ELSE (tm.mention_count - b.mean_mentions) / b.sd_mentions END,
                zscore_sentiment = CASE
                    WHEN b.sd_sentiment IS NULL OR b.sd_sentiment = 0 THEN NULL
                    ELSE (tm.weighted_sentiment - b.mean_sentiment) / b.sd_sentiment END,
                updated_at = NOW()
            FROM baseline b
            WHERE tm.ticker_id = b.ticker_id
              AND tm.interval = ?
              AND tm.bucket_start >= NOW() - ?::interval
        SQL, [$this->interval, $baselineDays, $this->interval, $lookback]);
    }
}
