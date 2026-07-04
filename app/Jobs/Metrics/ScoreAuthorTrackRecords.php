<?php

namespace App\Jobs\Metrics;

use App\Models\Author;
use App\Models\BacktestRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Author track record: of the backtest candidate days this author posted
 * into, how often did the ticker actually hit (+30% within 5 sessions)?
 * Graded against the latest completed backtest run's event labels so the
 * definition matches everything else in the research stack.
 *
 * Scores are Laplace-smoothed toward 50% so a 2-for-2 newcomer doesn't
 * outrank a 20-for-30 veteran.
 */
class ScoreAuthorTrackRecords implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    protected const MIN_GRADED_MENTIONS = 3;

    public function __construct()
    {
        $this->onQueue('metrics');
    }

    public function handle(): void
    {
        $run = BacktestRun::query()->where('status', 'done')->latest('id')->first();

        if ($run === null) {
            return;
        }

        // Inner DISTINCT: one row per (author, event) so multi-posting into
        // the same ticker-day counts once.
        $rows = DB::select(<<<'SQL'
            SELECT author_id, COUNT(*) AS n, SUM(CASE WHEN hit THEN 1 ELSE 0 END) AS hits
            FROM (
                SELECT DISTINCT p.author_id, e.id AS event_id, e.hit
                FROM post_ticker_mentions m
                JOIN raw_posts p ON p.id = m.raw_post_id
                JOIN backtest_events e
                  ON e.backtest_run_id = ?
                 AND e.ticker_id = m.ticker_id
                 AND e.day = date(m.posted_at)
                WHERE p.author_id IS NOT NULL
            ) graded
            GROUP BY author_id
            HAVING COUNT(*) >= ?
        SQL, [$run->id, self::MIN_GRADED_MENTIONS]);

        foreach ($rows as $row) {
            Author::query()->whereKey($row->author_id)->update([
                'track_record_score' => round(((int) $row->hits + 1) / ((int) $row->n + 2), 4),
                'track_record_n' => (int) $row->n,
            ]);
        }
    }
}
