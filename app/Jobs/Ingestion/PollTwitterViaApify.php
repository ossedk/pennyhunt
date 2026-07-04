<?php

namespace App\Jobs\Ingestion;

use App\Events\FeedUpdated;
use App\Models\Source;
use App\Services\Ingestion\ApifyClient;
use App\Services\Ingestion\TwitterIngestor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * X/Twitter cashtag confirmation via apidojo/twitter-scraper-lite.
 *
 * Deliberately NOT a discovery firehose: Twitter's cashtag noise (crypto,
 * bots, engagement farms) makes broad scraping expensive and dirty. Instead,
 * each run searches the cashtags of tickers that are already trending on
 * Reddit, chunked into OR-queries of 10. That yields an independent
 * cross-platform read on exactly the names the signal engine cares about —
 * mention counts flow into ticker_metrics (breadth/acceleration) and the
 * tweets carry sentiment like any other post.
 *
 * Cost model (paid Apify plan): ~$0.016 per query (first ~40 tweets
 * included) + ~$0.0004-0.0012 per tweet beyond that. At 30 tickers/run =
 * 3 queries hourly, that's ~$1.20-2.50/day depending on tweet volume.
 */
class PollTwitterViaApify implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(ApifyClient $client, TwitterIngestor $ingestor): void
    {
        $config = config('pennyhunt.apify.twitter');

        if (! $client->isConfigured() || ! $config['enabled']) {
            return;
        }

        $source = Source::query()
            ->where('key', 'twitter:cashtags')
            ->where('enabled', true)
            ->first();

        if ($source === null) {
            return;
        }

        $symbols = $this->trendingSymbols(
            (int) $config['max_tickers'],
            (int) $config['min_mentions'],
            (int) $config['lookback_hours'],
        );

        if ($symbols === []) {
            $source->markPolled();

            return;
        }

        try {
            $items = $client->runActor(
                $config['actor'],
                $this->buildInput($symbols, (int) $config['max_items']),
                maxWaitSeconds: 480,
            );
        } catch (Throwable $e) {
            $source->markFailed($e->getMessage());

            throw $e;
        }

        $ingested = $ingestor->ingest($source, $items);

        if ($ingested > 0) {
            FeedUpdated::dispatch($source->key, $ingested);
        }

        $source->markPolled();
    }

    /**
     * Tickers with meaningful Reddit traction in the lookback window, most
     * mentioned first. These are the names worth paying Twitter queries for.
     *
     * @return array<int, string>
     */
    protected function trendingSymbols(int $limit, int $minMentions, int $lookbackHours): array
    {
        return DB::table('post_ticker_mentions')
            ->join('tickers', 'tickers.id', '=', 'post_ticker_mentions.ticker_id')
            ->where('post_ticker_mentions.posted_at', '>=', now()->subHours($lookbackHours))
            ->where('tickers.is_active', true)
            ->groupBy('tickers.symbol')
            ->havingRaw('COUNT(*) >= ?', [$minMentions])
            ->orderByRaw('COUNT(*) DESC')
            ->limit($limit)
            ->pluck('tickers.symbol')
            ->all();
    }

    /**
     * OR-chained cashtag searches, 10 symbols per query (Twitter's advanced
     * search handles this fine and it keeps the per-query cost flat).
     * Retweets are excluded — they inflate mention counts without adding
     * independent authors — and min_faves filters zero-engagement bot spam
     * server-side, so we don't pay per-item for junk.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, mixed>
     */
    protected function buildInput(array $symbols, int $maxItems): array
    {
        $minLikes = (int) config('pennyhunt.apify.twitter.min_likes');

        $searchTerms = array_map(
            fn (array $chunk): string => '('.implode(' OR ', array_map(fn (string $s) => '$'.$s, $chunk)).')'
                .' -filter:retweets'
                .($minLikes > 0 ? " min_faves:{$minLikes}" : ''),
            array_chunk($symbols, 10),
        );

        return [
            'searchTerms' => $searchTerms,
            'sort' => 'Latest',
            'maxItems' => $maxItems,
            'tweetLanguage' => 'en',
        ];
    }
}
