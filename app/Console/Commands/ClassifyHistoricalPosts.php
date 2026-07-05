<?php

namespace App\Console\Commands;

use App\Models\BacktestRun;
use App\Models\RawPost;
use App\Services\Nlp\LlmPostClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Targeted historical LLM classification: only posts that sit on a backtest
 * candidate (ticker, day) get classified — the ~2-5% of the archive that can
 * actually move a model feature. Classifying everything would cost 20-50x
 * more for no training benefit.
 */
class ClassifyHistoricalPosts extends Command
{
    protected $signature = 'pennyhunt:classify-posts
        {--run= : Backtest run whose candidate days define the target set (default: latest done)}
        {--limit=1000 : Max posts to classify this invocation}
        {--workers=1 : Total parallel workers (modulo sharding on post id)}
        {--worker=0 : This worker\'s shard index (0-based)}
        {--dry-run : Count the target set without spending}';

    protected $description = 'LLM-classify historical posts on backtest candidate days (targeted, cost-bounded)';

    public function handle(LlmPostClassifier $classifier): int
    {
        if (! $classifier->enabled() && ! $this->option('dry-run')) {
            $this->error('Neither OPENAI_API_KEY nor ANTHROPIC_API_KEY is configured.');

            return self::FAILURE;
        }

        $run = $this->option('run')
            ? BacktestRun::findOrFail((int) $this->option('run'))
            : BacktestRun::query()->where('status', 'done')->latest('id')->first();

        if ($run === null) {
            $this->error('No completed backtest run found.');

            return self::FAILURE;
        }

        $workers = max(1, (int) $this->option('workers'));
        $worker = (int) $this->option('worker');

        // Posts mentioning a ticker on one of the run's candidate days,
        // not yet LLM-classified. Modulo sharding on post id lets N workers
        // run concurrently on disjoint sets — no locking needed.
        $query = DB::table('raw_posts as p')
            ->join('post_ticker_mentions as m', 'm.raw_post_id', '=', 'p.id')
            ->join('backtest_events as e', function ($join) use ($run): void {
                $join->on('e.ticker_id', '=', 'm.ticker_id')
                    ->where('e.backtest_run_id', $run->id)
                    ->whereRaw('e.day = date(m.posted_at)');
            })
            ->leftJoin('post_sentiments as s', 's.raw_post_id', '=', 'p.id')
            ->whereNull('s.llm_post_type')
            ->when($workers > 1, fn ($q) => $q->whereRaw('p.id % ? = ?', [$workers, $worker]))
            ->distinct()
            ->select('p.id');

        $total = $query->count('p.id');
        $shard = $workers > 1 ? " (shard {$worker}/{$workers})" : '';
        $this->info("Run #{$run->id}: {$total} unclassified candidate-day posts{$shard}.");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $ids = $query->orderBy('p.id')->limit((int) $this->option('limit'))->pluck('p.id');
        $done = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $post = RawPost::find($id);

            // A multi-day backfill must survive transient API/network errors:
            // swallow, back off, move on — unclassified posts are re-selected
            // on the next invocation anyway.
            try {
                if ($post !== null && $classifier->classifyAndStore($post)) {
                    $done++;
                } else {
                    $failed++;
                }
            } catch (\Throwable) {
                $failed++;
                Sleep::for(5)->seconds();
            }

            $this->output->write("\r  classified {$done}/".count($ids)." (failed {$failed})   ");
            Sleep::for(200)->milliseconds(); // stay under API rate limits
        }

        $this->output->writeln('');
        $this->info("Done. {$done} posts classified, ".($total - $done).' remaining.');

        return self::SUCCESS;
    }
}
