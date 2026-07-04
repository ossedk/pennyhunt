<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\Ingestion\ArcticShiftClient;
use App\Services\Ingestion\RedditIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Backfills historical Reddit posts from the Arctic Shift archive through the
 * exact same ingestion pipeline as live data (RedditIngestor -> ProcessRawPost
 * -> ticker extraction + sentiment). Runs for a while; safe to re-run —
 * dedupe on (source_id, external_id) makes it idempotent.
 */
class BackfillRedditHistory extends Command
{
    protected $signature = 'pennyhunt:backfill-reddit
        {--months=6 : How many months of history to backfill}
        {--subreddit= : Limit to a single subreddit}
        {--until= : Backfill up to this date (default: start of live ingestion)}';

    protected $description = 'Backfill historical Reddit posts from Arctic Shift for backtesting';

    public function handle(ArcticShiftClient $client, RedditIngestor $ingestor): int
    {
        $after = CarbonImmutable::now()->subMonths((int) $this->option('months'))->startOfDay();
        $before = $this->option('until')
            ? CarbonImmutable::parse($this->option('until'))
            : CarbonImmutable::parse('2026-07-01'); // live Apify archive starts here

        $sources = Source::query()
            ->where('type', 'reddit')
            ->where('enabled', true)
            ->when($this->option('subreddit'), fn ($q, $sub) => $q->where('config->subreddit', $sub))
            ->get();

        $this->info("Backfilling {$sources->count()} subreddits: {$after->toDateString()} -> {$before->toDateString()}");

        $grandTotal = 0;

        foreach ($sources as $source) {
            $subreddit = $source->config['subreddit'];
            $count = 0;
            $batch = [];

            foreach ($client->posts($subreddit, $after->timestamp, $before->timestamp) as $item) {
                $batch[] = $this->toRedditThing($item);

                if (count($batch) >= 100) {
                    $count += $ingestor->ingest($source, $batch, 'post');
                    $batch = [];
                    $this->output->write("\r  r/{$subreddit}: {$count} ingested");
                }
            }

            if ($batch !== []) {
                $count += $ingestor->ingest($source, $batch, 'post');
            }

            $this->output->writeln("\r  r/{$subreddit}: {$count} ingested ✓");
            $grandTotal += $count;
        }

        $this->info("Done. {$grandTotal} historical posts ingested (pipeline jobs queued).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function toRedditThing(array $item): array
    {
        return [
            'name' => 't3_'.$item['id'],
            'title' => $item['title'] ?? null,
            'selftext' => in_array($item['selftext'] ?? null, ['[removed]', '[deleted]'], true)
                ? null
                : ($item['selftext'] ?? null),
            'permalink' => '/comments/'.$item['id'].'/',
            'score' => (int) ($item['score'] ?? 0),
            'num_comments' => (int) ($item['num_comments'] ?? 0),
            'created_utc' => (int) $item['created_utc'],
            'author' => $item['author'] ?? null,
            'subreddit' => $item['subreddit'] ?? null,
            'upvote_ratio' => null,
            'link_flair_text' => $item['link_flair_text'] ?? null,
        ];
    }
}
