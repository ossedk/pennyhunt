<?php

namespace App\Console\Commands;

use App\Models\Ticker;
use App\Models\TickerNews;
use App\Services\MarketData\PolygonClient;
use App\Services\Nlp\NewsCatalystClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Historical news backfill so the news_catalyst_7d / news_offering_7d
 * features have coverage across the backtest window, not just since the
 * news feature shipped. One Polygon request per ticker (up to 1000
 * articles), then LLM catalyst classification in batches.
 */
class BackfillTickerNews extends Command
{
    protected $signature = 'pennyhunt:backfill-news
        {--months=24 : History window}
        {--min-mentions=10 : Only tickers with at least this many mentions}
        {--limit=0 : Cap tickers processed this run (0 = all)}
        {--classify : Run LLM catalyst classification afterwards}';

    protected $description = 'Backfill historical Polygon news for mentioned tickers';

    public function handle(PolygonClient $polygon, NewsCatalystClassifier $classifier): int
    {
        if (! $polygon->enabled()) {
            $this->error('Polygon API key not configured.');

            return self::FAILURE;
        }

        $from = now()->subMonths((int) $this->option('months'))->toDateString();

        $tickerIds = DB::table('post_ticker_mentions')
            ->select('ticker_id')
            ->where('posted_at', '>=', $from)
            ->groupBy('ticker_id')
            ->havingRaw('COUNT(*) >= ?', [(int) $this->option('min-mentions')])
            ->pluck('ticker_id');

        $tickers = Ticker::query()
            ->whereIn('id', $tickerIds)
            ->where('is_active', true)
            ->whereNull('meta->news_backfilled_at')
            ->orderBy('symbol')
            ->when((int) $this->option('limit') > 0, fn ($q) => $q->limit((int) $this->option('limit')))
            ->get();

        $this->info("Backfilling news for {$tickers->count()} tickers since {$from}");

        $stored = 0;

        foreach ($tickers as $i => $ticker) {
            $count = 0;

            foreach ($polygon->newsBetween($ticker->symbol, $from, now()->toDateString()) as $item) {
                if (! isset($item['id'], $item['title'], $item['article_url'], $item['published_utc'])) {
                    continue;
                }

                TickerNews::query()->updateOrCreate(
                    ['external_id' => (string) $item['id']],
                    [
                        'ticker_id' => $ticker->id,
                        'publisher' => data_get($item, 'publisher.name'),
                        'title' => (string) $item['title'],
                        'article_url' => (string) $item['article_url'],
                        'image_url' => $item['image_url'] ?? null,
                        'description' => isset($item['description']) ? mb_substr((string) $item['description'], 0, 1000) : null,
                        'published_at' => $item['published_utc'],
                    ],
                );

                $count++;
            }

            $ticker->forceFill(['meta' => [...($ticker->meta ?? []), 'news_backfilled_at' => now()->toIso8601String()]])->save();
            $stored += $count;

            $this->output->write("\r  {$ticker->symbol}: {$count} articles  [".($i + 1)."/{$tickers->count()}]   ");
        }

        $this->output->writeln('');
        $this->info("Stored {$stored} articles.");

        if ($this->option('classify')) {
            $pending = TickerNews::query()->whereNull('catalyst_classified_at')->count();
            $this->info("Classifying {$pending} headlines...");

            $done = $classifier->classifyPending($pending);
            $this->info("Classified {$done}.");
        }

        return self::SUCCESS;
    }
}
