<?php

namespace App\Console\Commands;

use App\Jobs\Metrics\BuildTickerMetrics;
use App\Models\RawPost;
use App\Services\Nlp\TickerExtractor;
use App\Support\Memory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-runs the TickerExtractor over historical posts and deletes mentions
 * the current (stricter) rules no longer produce — the retroactive arm of
 * every extractor precision upgrade. Cashtag mentions always survive;
 * bare-word matches are re-judged with the shouting guard, English-word
 * check and finance-cue rescue, and tweets drop to cashtag-only.
 *
 * By default only "suspect" posts are scanned: those holding a bare-word
 * mention on a symbol that collides with a common English word, plus all
 * twitter bare-word mentions. --all rescans every mentioned post.
 */
class ReextractMentions extends Command
{
    protected $signature = 'pennyhunt:reextract-mentions
        {--all : rescan every post with mentions, not just suspect ones}
        {--dry-run : report what would be deleted without touching anything}
        {--rebuild-days=60 : ticker_metrics lookback to rebuild afterwards}';

    protected $description = 'Re-judge historical ticker mentions with the current extractor rules';

    public function handle(TickerExtractor $extractor): int
    {
        Memory::raise('1024M');

        $postIds = $this->targetPostIds();

        $this->info(count($postIds).' posts to re-judge.');

        $deleted = 0;
        $downgraded = 0;

        foreach (array_chunk($postIds, 500) as $chunk) {
            $posts = RawPost::query()
                ->whereIn('id', $chunk)
                ->with(['source:id,type', 'mentions.ticker:id,symbol'])
                ->get();

            foreach ($posts as $post) {
                $fresh = $extractor->extract($post->fullText(), $post->source?->type);

                foreach ($post->mentions as $mention) {
                    $symbol = $mention->ticker?->symbol;

                    if ($symbol === null) {
                        continue;
                    }

                    if (! isset($fresh[$symbol])) {
                        $deleted++;

                        if (! $this->option('dry-run')) {
                            $mention->delete();
                        }

                        continue;
                    }

                    if ($fresh[$symbol]['method'] !== $mention->method) {
                        $downgraded++;

                        if (! $this->option('dry-run')) {
                            $mention->forceFill([
                                'method' => $fresh[$symbol]['method'],
                                'confidence' => $fresh[$symbol]['confidence'],
                            ])->save();
                        }
                    }
                }
            }

            $this->output->write('.');
        }

        $this->newLine();
        $this->info(($this->option('dry-run') ? '[dry-run] would delete ' : 'Deleted ').$deleted.' mentions, re-tiered '.$downgraded.'.');

        if (! $this->option('dry-run') && $deleted > 0) {
            $days = (int) $this->option('rebuild-days');

            // Purge stale buckets first: the rollup upsert only overwrites
            // buckets that still have surviving mentions.
            DB::table('ticker_metrics')->where('bucket_start', '>=', now()->subDays($days))->delete();

            foreach (['5m', '1h', '1d'] as $interval) {
                BuildTickerMetrics::dispatch($interval, $days.' days');
            }

            $this->info("Dispatched ticker_metrics rebuilds over {$days} days.");
        }

        return self::SUCCESS;
    }

    /** @return list<int> */
    protected function targetPostIds(): array
    {
        if ($this->option('all')) {
            return DB::table('post_ticker_mentions')->distinct()->pluck('raw_post_id')->all();
        }

        $words = array_filter(array_map('trim', file(resource_path('data/common-english-words.txt')) ?: []));

        return DB::query()->fromSub(
            DB::table('post_ticker_mentions as m')
                ->join('tickers as t', 't.id', '=', 'm.ticker_id')
                ->join('raw_posts as p', 'p.id', '=', 'm.raw_post_id')
                ->join('sources as s', 's.id', '=', 'p.source_id')
                ->where('m.method', '<>', 'cashtag')
                ->where(fn ($q) => $q
                    ->whereIn('t.symbol', $words)
                    ->orWhereIn('t.symbol', config('pennyhunt.ambiguous_symbols'))
                    ->orWhere('s.type', 'twitter'))
                ->select('m.raw_post_id'),
            'suspects',
        )->distinct()->pluck('raw_post_id')->all();
    }
}
