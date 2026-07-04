<?php

namespace App\Console\Commands;

use App\Models\BacktestRun;
use App\Services\Features\LlmAggregates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recompute the LLM aggregate features onto an existing run's backtest_events
 * without re-running the backtest. The LLM classification backfill is
 * incremental (days-long, spend-capped), so this is designed to be re-run:
 * each pass folds in whatever has been classified since the last one.
 *
 * Typical flow: pennyhunt:classify-posts finishes (or reaches good coverage)
 * → pennyhunt:backfill-llm-features --run=32 → pennyhunt:train-gbm --run=32.
 */
class BackfillLlmFeatures extends Command
{
    protected $signature = 'pennyhunt:backfill-llm-features {--run= : Backtest run id (default: latest done run)}';

    protected $description = 'Recompute LLM aggregate features onto an existing backtest run\'s events';

    public function handle(): int
    {
        ini_set('memory_limit', '1024M'); // bulk aggregate load over 24 months

        $run = $this->option('run')
            ? BacktestRun::findOrFail((int) $this->option('run'))
            : BacktestRun::query()->where('status', 'done')->orderByDesc('finished_at')->firstOrFail();

        $tickerIds = $run->events()->distinct()->pluck('ticker_id');

        $this->info("Run #{$run->id}: loading LLM aggregates for {$tickerIds->count()} tickers ({$run->params['from']} → {$run->params['to']})...");

        $llm = LlmAggregates::load($tickerIds->all(), $run->params['from'], $run->params['to']);

        $updated = 0;
        $withCoverage = 0;

        $run->events()
            ->select('id', 'ticker_id', 'day')
            ->orderBy('id')
            ->chunkById(2000, function ($events) use ($llm, &$updated, &$withCoverage): void {
                foreach ($events as $event) {
                    $features = $llm->features($event->ticker_id, $event->day->toDateString());

                    DB::table('backtest_events')->where('id', $event->id)->update($features);
                    $updated++;

                    if ($features['llm_coverage'] > 0) {
                        $withCoverage++;
                    }
                }
            });

        $this->info(sprintf(
            'Done. %d events updated; %d (%.1f%%) have LLM coverage > 0.',
            $updated,
            $withCoverage,
            $updated > 0 ? 100 * $withCoverage / $updated : 0,
        ));

        return self::SUCCESS;
    }
}
