<?php

namespace App\Jobs\Ingestion;

use App\Models\AggregatorSnapshot;
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ApeWisdom: free, keyless mention-count aggregator across ~15 subreddits
 * and 4chan /biz. Used for cross-validation of our own Reddit ingestion
 * and as coverage while Reddit credentials are not yet configured.
 */
class PollApeWisdom implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $sourceId)
    {
        $this->onQueue('ingestion');
    }

    public function handle(): void
    {
        $source = Source::findOrFail($this->sourceId);

        if (! $source->enabled) {
            return;
        }

        try {
            $capturedAt = now();
            $page = 1;
            $maxPages = 3; // top 300 tickers is plenty

            do {
                // Cloudflare blocks Guzzle's default UA; a browser UA passes
                $response = Http::timeout(30)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'])
                    ->get("https://apewisdom.io/api/v1.0/filter/all-stocks/page/{$page}")
                    ->throw()
                    ->json();

                foreach ($response['results'] ?? [] as $row) {
                    AggregatorSnapshot::create([
                        'source_id' => $source->id,
                        'symbol' => strtoupper($row['ticker']),
                        'rank' => (int) $row['rank'],
                        'mentions' => (int) ($row['mentions'] ?? 0),
                        'upvotes' => (int) ($row['upvotes'] ?? 0),
                        'mentions_24h_ago' => (int) ($row['mentions_24h_ago'] ?? 0),
                        'rank_24h_ago' => (int) ($row['rank_24h_ago'] ?? 0),
                        'captured_at' => $capturedAt,
                    ]);
                }

                $page++;
            } while ($page <= min((int) ($response['pages'] ?? 1), $maxPages));

            $source->markPolled();
        } catch (Throwable $e) {
            $source->markFailed($e->getMessage());

            throw $e;
        }
    }
}
