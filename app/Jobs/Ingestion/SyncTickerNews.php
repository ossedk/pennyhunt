<?php

namespace App\Jobs\Ingestion;

use App\Models\Ticker;
use App\Models\TickerNews;
use App\Services\MarketData\PolygonClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Pulls the latest Polygon news articles for one ticker into ticker_news
 * (idempotent upsert on Polygon's article id). Dispatched lazily from the
 * ticker page and in bulk for trending tickers; the cooldown keeps page
 * views from hammering the API.
 */
class SyncTickerNews implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public const COOLDOWN_HOURS = 6;

    public function __construct(public int $tickerId)
    {
        $this->onQueue('ingestion');
    }

    /** True when a sync was dispatched (cooldown not yet active). */
    public static function dispatchIfStale(int $tickerId): bool
    {
        if (! Cache::add('news-sync:'.$tickerId, 1, now()->addHours(self::COOLDOWN_HOURS))) {
            return false;
        }

        self::dispatch($tickerId);

        return true;
    }

    public function handle(PolygonClient $polygon): void
    {
        $ticker = Ticker::query()->find($this->tickerId);

        if ($ticker === null || ! $polygon->enabled()) {
            return;
        }

        foreach ($polygon->news($ticker->symbol, 12) as $item) {
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
        }
    }
}
