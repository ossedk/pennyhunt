<?php

namespace App\Jobs\Ingestion;

use App\Models\Source;
use App\Services\Ingestion\StocktwitsIngestor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Stocktwits candidate flow (Phase G): the trending stream every run plus
 * symbol streams for the loudest recent tickers. Unauthenticated API,
 * ~200 req/hr limit — this job uses ~11 per run, scheduled every 15 min
 * (≈ 44/hr), comfortably inside it.
 */
class PollStocktwits implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    protected const SYMBOL_STREAMS_PER_RUN = 10;

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(StocktwitsIngestor $ingestor): void
    {
        $source = Source::query()->firstOrCreate(
            ['key' => 'stocktwits:trending'],
            [
                'type' => 'stocktwits',
                'name' => 'Stocktwits (trending + loud tickers)',
                'enabled' => true,
                'poll_interval_seconds' => 900,
                'config' => [],
            ],
        );

        if (! $source->enabled) {
            return;
        }

        $ingested = $this->pull($ingestor, $source, 'https://api.stocktwits.com/api/2/streams/trending.json');

        // Symbol streams for the loudest tickers of the last 24h — deeper
        // coverage where the crowd already is.
        $loud = DB::table('post_ticker_mentions as m')
            ->join('tickers as t', 't.id', '=', 'm.ticker_id')
            ->where('m.posted_at', '>=', now()->subDay())
            ->where('t.is_active', true)
            ->groupBy('t.symbol')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(self::SYMBOL_STREAMS_PER_RUN)
            ->pluck('t.symbol');

        foreach ($loud as $symbol) {
            $ingested += $this->pull($ingestor, $source, 'https://api.stocktwits.com/api/2/streams/symbol/'.rawurlencode($symbol).'.json');
            Sleep::for(500)->milliseconds();
        }
    }

    protected function pull(StocktwitsIngestor $ingestor, Source $source, string $url): int
    {
        $response = Http::timeout(20)
            ->retry(1, 2000, throw: false)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)'])
            ->get($url);

        if (! $response->successful()) {
            return 0;
        }

        return $ingestor->ingest($source, $response->json('messages') ?? []);
    }
}
