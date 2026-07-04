<?php

namespace App\Jobs\Ingestion;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Keeps news warm for the tickers people are actually talking about, so
 * the Desk's "top hyped news" join never waits on Polygon. Fans out to
 * SyncTickerNews, which carries the per-ticker cooldown.
 */
class SyncTrendingNews implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 3000;

    public const TOP_TICKERS = 25;

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(): void
    {
        DB::table('post_ticker_mentions')
            ->join('tickers', 'tickers.id', '=', 'post_ticker_mentions.ticker_id')
            ->where('post_ticker_mentions.posted_at', '>=', now()->subHours(24))
            ->where('tickers.is_active', true)
            ->groupBy('tickers.id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(self::TOP_TICKERS)
            ->pluck('tickers.id')
            ->each(fn (int $tickerId) => SyncTickerNews::dispatchIfStale($tickerId));
    }
}
