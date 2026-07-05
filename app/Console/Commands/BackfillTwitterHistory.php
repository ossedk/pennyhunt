<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\Ingestion\ApifyClient;
use App\Services\Ingestion\TwitterIngestor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Historical X/Twitter backfill for the Voices leaderboard: pulls cashtag
 * tweets for the windows where calls actually get graded — the latest
 * backtest run's candidate days.
 *
 * Survivorship-bias guard: the candidate set is defined by BUZZ (mention
 * activity), never by outcome, so callers on losers get pulled and graded
 * just like callers on winners. Backfilling only "stocks that exploded"
 * would make every X account look like a genius.
 *
 * Cost control: min_faves floor in the query itself, merged windows
 * (candidate day -3 → +2, gaps bridged), terms batched per actor run,
 * hard per-run item cap, --limit on tickers, and --dry-run to price a run
 * before spending.
 */
class BackfillTwitterHistory extends Command
{
    protected $signature = 'pennyhunt:backfill-twitter
        {--run= : Backtest run whose candidate days define the windows (default: latest done)}
        {--limit=300 : Tickers processed, ordered by candidate-event count}
        {--max-price=5 : Only candidate days with entry at/below this (the tradeable universe)}
        {--max-windows=12 : Skip tickers with more merged windows than this (perma-buzz megacaps)}
        {--batch=30 : Search terms per Apify actor run}
        {--per-term=20 : Item budget per search term}
        {--dry-run : Plan and price without calling Apify}';

    protected $description = 'Backfill historical X posts over backtest candidate windows (feeds the X Voices board)';

    public function handle(ApifyClient $client, TwitterIngestor $ingestor): int
    {
        $config = config('pennyhunt.apify.twitter');

        if (! $this->option('dry-run') && (! $client->isConfigured() || ! $config['enabled'])) {
            $this->error('Apify twitter is not configured/enabled.');

            return self::FAILURE;
        }

        $runId = $this->option('run')
            ? (int) $this->option('run')
            : (int) DB::table('backtest_runs')->where('status', 'done')->orderByDesc('id')->value('id');

        $source = Source::query()->where('key', 'twitter:cashtags')->first();

        if ($source === null) {
            $this->error('twitter:cashtags source missing.');

            return self::FAILURE;
        }

        // Candidate days per ticker (buzz-defined — hit AND non-hit days),
        // restricted to the tradeable price band: X callers on NVDA/TSLA
        // are noise for a penny-stock board and eat the item budget.
        $rows = DB::table('backtest_events')
            ->where('backtest_run_id', $runId)
            ->where('entry', '<=', (float) $this->option('max-price'))
            ->orderBy('symbol')
            ->orderBy('day')
            ->get(['symbol', 'day'])
            ->groupBy('symbol')
            ->sortByDesc(fn ($days) => $days->count())
            ->take((int) $this->option('limit'));

        $minLikes = (int) $config['min_likes'];
        $maxWindows = (int) $this->option('max-windows');
        $terms = [];
        $skipped = 0;

        foreach ($rows as $symbol => $days) {
            $windows = $this->mergeWindows($days->pluck('day')->unique()->sort()->values()->all());

            if (count($windows) > $maxWindows) {
                $skipped++;

                continue;
            }

            foreach ($windows as [$from, $to]) {
                $terms[] = sprintf(
                    '$%s min_faves:%d since:%s until:%s -filter:retweets',
                    $symbol,
                    $minLikes,
                    $from,
                    $to,
                );
            }
        }

        if ($skipped > 0) {
            $this->line("  ({$skipped} perma-buzz tickers skipped: > {$maxWindows} windows)");
        }

        $batchSize = max(1, (int) $this->option('batch'));
        $perTerm = max(1, (int) $this->option('per-term'));
        $batches = array_chunk($terms, $batchSize);
        $estimatedItems = count($terms) * $perTerm;

        $this->info(sprintf(
            'Run #%d: %d tickers → %d search windows → %d actor runs, ≤%s items (~$%.2f at $0.40/1k).',
            $runId,
            $rows->count(),
            count($terms),
            count($batches),
            number_format($estimatedItems),
            $estimatedItems * 0.0004,
        ));

        if ($this->option('dry-run')) {
            foreach (array_slice($terms, 0, 5) as $term) {
                $this->line('  e.g. '.$term);
            }

            return self::SUCCESS;
        }

        $ingested = 0;

        foreach ($batches as $i => $batch) {
            $items = $client->runActor(
                $config['actor'],
                [
                    'searchTerms' => $batch,
                    'sort' => 'Latest',
                    'maxItems' => count($batch) * $perTerm,
                    'tweetLanguage' => 'en',
                ],
                maxWaitSeconds: 480,
            );

            $ingested += count($items);
            $ingestor->ingest($source, $items);

            $this->output->write(sprintf("\r  batch %d/%d — %s tweets ingested   ", $i + 1, count($batches), number_format($ingested)));
        }

        $this->output->writeln('');
        $this->info("Done. {$ingested} tweets ingested — mentions/classification flow through the normal pipeline.");
        $this->line('Next: pennyhunt:rebuild-voices (or wait for the Monday build) to grade the new calls.');

        return self::SUCCESS;
    }

    /**
     * Merge candidate days into [day-3, day+2] windows, bridging gaps of
     * less than 4 days — one search term per contiguous buzz episode.
     *
     * @param  list<string>  $days  sorted Y-m-d
     * @return list<array{0: string, 1: string}>
     */
    protected function mergeWindows(array $days): array
    {
        $windows = [];
        $start = null;
        $end = null;

        foreach ($days as $day) {
            $from = gmdate('Y-m-d', strtotime($day.' UTC') - 3 * 86400);
            $to = gmdate('Y-m-d', strtotime($day.' UTC') + 2 * 86400);

            if ($end !== null && $from <= $end) {
                $end = max($end, $to);

                continue;
            }

            if ($start !== null) {
                $windows[] = [$start, $end];
            }

            [$start, $end] = [$from, $to];
        }

        if ($start !== null) {
            $windows[] = [$start, $end];
        }

        return $windows;
    }
}
