<?php

namespace App\Services\Features;

use App\Support\AnalyticsGate;
use Illuminate\Support\Facades\DB;

/**
 * Per-(ticker, day) aggregates of the LLM post classifications — the phase-B
 * feature block. Preloads a window in bulk, then answers per-day queries:
 *
 *  - llm_coverage: share of the day's mention posts that carry an LLM
 *    verdict. The historical backfill is incremental and the live pipeline
 *    is spend-capped, so coverage varies; the model needs it to discount
 *    thin days (all other aggregates default to 0 when nothing is scored).
 *  - llm_direction: mean direction over classified posts (bullish=+1,
 *    neutral=0, bearish=−1).
 *  - llm_conviction / llm_pump_suspicion: mean scores over classified posts.
 *  - llm_dd_share / llm_hype_share / llm_news_share: post-type composition.
 *    The v2 weight-fit lesson was that *polarity* is worthless but substance
 *    might not be: DD and hype read identically to the lexicon.
 *  - llm_catalyst_share: share of classified posts claiming a dated catalyst.
 *
 * Same instance serves the Backtester (bulk, historical) and the
 * SignalEngine (today) so definitions cannot drift between research and live.
 *
 * Look-ahead note: classifications are produced by a fixed prompt over the
 * post text itself (no future information), so applying today's classifier
 * to historical posts is point-in-time safe — unlike, say, author track
 * records, which are graded against realized outcomes.
 */
class LlmAggregates
{
    /** @var array<int, array<string, array<string, float>>> [tickerId => [day => aggregates]] */
    protected array $days = [];

    public const FEATURE_KEYS = [
        'llm_coverage', 'llm_direction', 'llm_conviction', 'llm_pump_suspicion',
        'llm_dd_share', 'llm_hype_share', 'llm_news_share', 'llm_catalyst_share',
    ];

    /** @param array<int, int> $tickerIds */
    public static function load(array $tickerIds, string $from, string $to): self
    {
        $self = new self;

        if ($tickerIds === []) {
            return $self;
        }

        // One pass over the window's mentions: total posts per (ticker, day)
        // for coverage, plus conditional aggregates over the classified
        // subset. CASE/AVG keeps it portable between Postgres and SQLite.
        $gate = AnalyticsGate::mentionJoin('m');

        $rows = DB::select(<<<SQL
            SELECT
                m.ticker_id,
                date(m.posted_at) AS day,
                COUNT(*) AS total,
                COUNT(s.llm_post_type) AS classified,
                AVG(CASE WHEN s.llm_post_type IS NULL THEN NULL WHEN s.llm_direction = 'bullish' THEN 1.0 WHEN s.llm_direction = 'bearish' THEN -1.0 ELSE 0.0 END) AS direction,
                AVG(s.llm_conviction) AS conviction,
                AVG(s.llm_pump_suspicion) AS pump_suspicion,
                AVG(CASE WHEN s.llm_post_type = 'dd' THEN 1.0 WHEN s.llm_post_type IS NULL THEN NULL ELSE 0.0 END) AS dd_share,
                AVG(CASE WHEN s.llm_post_type = 'hype' THEN 1.0 WHEN s.llm_post_type IS NULL THEN NULL ELSE 0.0 END) AS hype_share,
                AVG(CASE WHEN s.llm_post_type = 'news' THEN 1.0 WHEN s.llm_post_type IS NULL THEN NULL ELSE 0.0 END) AS news_share,
                AVG(CASE WHEN s.llm_post_type IS NULL THEN NULL WHEN s.llm_catalyst THEN 1.0 ELSE 0.0 END) AS catalyst_share
            FROM post_ticker_mentions m
            {$gate}
            LEFT JOIN post_sentiments s
                ON s.raw_post_id = m.raw_post_id AND s.llm_post_type IS NOT NULL
            WHERE m.posted_at >= ? AND m.posted_at < ?
            GROUP BY m.ticker_id, day
        SQL, [$from, gmdate('Y-m-d', strtotime($to.' UTC') + 86400)]);

        $wanted = array_flip($tickerIds);

        foreach ($rows as $row) {
            if (! isset($wanted[(int) $row->ticker_id]) || (int) $row->classified === 0) {
                continue;
            }

            $self->days[(int) $row->ticker_id][(string) $row->day] = [
                'llm_coverage' => round((int) $row->classified / (int) $row->total, 4),
                'llm_direction' => round((float) $row->direction, 4),
                'llm_conviction' => round((float) $row->conviction, 4),
                'llm_pump_suspicion' => round((float) $row->pump_suspicion, 4),
                'llm_dd_share' => round((float) $row->dd_share, 4),
                'llm_hype_share' => round((float) $row->hype_share, 4),
                'llm_news_share' => round((float) $row->news_share, 4),
                'llm_catalyst_share' => round((float) $row->catalyst_share, 4),
            ];
        }

        return $self;
    }

    /**
     * Aggregates for a (ticker, day). Days without a single classified post
     * return coverage 0 and neutral zeros — the "we know nothing" vector.
     *
     * @return array<string, float>
     */
    public function features(int $tickerId, string $day): array
    {
        return $this->days[$tickerId][$day]
            ?? array_fill_keys(self::FEATURE_KEYS, 0.0);
    }
}
