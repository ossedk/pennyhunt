<?php

namespace App\Jobs\Ingestion;

use App\Models\Source;
use App\Models\Ticker;
use App\Services\Ingestion\ApifyClient;
use App\Services\Ingestion\TwitterIngestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * On-demand X/Twitter tape refresh for a single ticker — dispatched when
 * a human shows intent (opens the ticker page, or an exact search hit).
 * One cashtag query with maxItems capped to the first Apify pricing tier
 * (~$0.016/pull), behind a 30-minute per-ticker cooldown. Tweets pass the
 * usual ingestion gates and remain display-only for analytics
 * (AnalyticsGate quarantine).
 */
class PullTwitterForTicker implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public const COOLDOWN_MINUTES = 30;

    public const MAX_ITEMS = 40;

    public function __construct(public int $tickerId)
    {
        $this->onQueue('ingestion');
    }

    /** True when a pull was dispatched (cooldown not yet active). */
    public static function dispatchIfStale(int $tickerId): bool
    {
        if (! config('pennyhunt.apify.twitter.enabled') || blank(config('pennyhunt.apify.token'))) {
            return false;
        }

        if (! Cache::add('twitter-pull:'.$tickerId, 1, now()->addMinutes(self::COOLDOWN_MINUTES))) {
            return false;
        }

        self::dispatch($tickerId);

        return true;
    }

    public function handle(ApifyClient $client, TwitterIngestor $ingestor): void
    {
        $config = config('pennyhunt.apify.twitter');

        if (! $client->isConfigured() || ! $config['enabled']) {
            return;
        }

        $ticker = Ticker::query()->find($this->tickerId);
        $source = Source::query()->where('key', 'twitter:cashtags')->where('enabled', true)->first();

        if ($ticker === null || $source === null) {
            return;
        }

        $minLikes = (int) $config['min_likes'];

        $items = $client->runActor(
            $config['actor'],
            [
                'searchTerms' => [
                    '$'.$ticker->symbol.' -filter:retweets'.($minLikes > 0 ? " min_faves:{$minLikes}" : ''),
                ],
                'sort' => 'Latest',
                'maxItems' => self::MAX_ITEMS,
                'tweetLanguage' => 'en',
            ],
            maxWaitSeconds: 240,
        );

        $ingestor->ingest($source, $items);
    }
}
