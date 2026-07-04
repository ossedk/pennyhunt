<?php

namespace App\Jobs\Ingestion;

use App\Models\AggregatorSnapshot;
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tradestie: free, keyless top-50 r/wallstreetbets tickers with a
 * provider-computed sentiment label and score.
 */
class PollTradestie implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

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
            $rows = Http::timeout(30)
                ->get('https://tradestie.com/api/v1/apps/reddit')
                ->throw()
                ->json();

            $capturedAt = now();

            foreach ($rows ?? [] as $index => $row) {
                AggregatorSnapshot::create([
                    'source_id' => $source->id,
                    'symbol' => strtoupper($row['ticker']),
                    'rank' => $index + 1,
                    'mentions' => (int) ($row['no_of_comments'] ?? 0),
                    'sentiment_label' => $row['sentiment'] ?? null,
                    'sentiment_score' => isset($row['sentiment_score']) ? (float) $row['sentiment_score'] : null,
                    'captured_at' => $capturedAt,
                ]);
            }

            $source->markPolled();
        } catch (Throwable $e) {
            $source->markFailed($e->getMessage());

            throw $e;
        }
    }
}
