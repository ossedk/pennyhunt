<?php

namespace App\Jobs\Ingestion;

use App\Models\Ticker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Syncs the active ticker universe.
 *
 * Primary source: SEC company_tickers.json (free, no key, all SEC registrants
 * including OTC filers). If an FMP key is configured, exchange/price metadata
 * is enriched from FMP's stock list.
 */
class SyncTickerUniverse implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(): void
    {
        $this->syncFromSec();

        if (filled(config('pennyhunt.fmp.api_key'))) {
            $this->enrichFromFmp();
        }

        Cache::forget('tickers:active_symbols');

        // Flag symbols colliding with common words (curated list + top-10k
        // English words) — the UI badge that warns "bare-word matches for
        // this symbol are unreliable".
        $englishWords = array_filter(array_map('trim', file(resource_path('data/common-english-words.txt')) ?: []));

        Ticker::query()
            ->whereIn('symbol', [...config('pennyhunt.ambiguous_symbols'), ...$englishWords])
            ->update(['is_ambiguous' => true]);
    }

    protected function syncFromSec(): void
    {
        // SEC fair-access policy requires a UA identifying the requester with contact info
        $companies = Http::timeout(120)
            ->withHeaders(['User-Agent' => config('pennyhunt.sec_user_agent')])
            ->get('https://www.sec.gov/files/company_tickers.json')
            ->throw()
            ->json();

        $rows = collect($companies)
            ->map(fn (array $company): array => [
                'symbol' => strtoupper($company['ticker']),
                'cik' => (int) $company['cik_str'],
                'name' => $company['title'],
                'is_active' => true,
            ])
            ->unique('symbol')
            ->values();

        foreach ($rows->chunk(1000) as $chunk) {
            Ticker::upsert($chunk->all(), uniqueBy: ['symbol'], update: ['cik', 'name', 'is_active']);
        }
    }

    protected function enrichFromFmp(): void
    {
        $list = Http::timeout(120)
            ->get('https://financialmodelingprep.com/api/v3/stock/list', [
                'apikey' => config('pennyhunt.fmp.api_key'),
            ])
            ->throw()
            ->json();

        $rows = collect($list)
            ->filter(fn (array $row): bool => filled($row['symbol'] ?? null))
            ->map(fn (array $row): array => [
                'symbol' => strtoupper($row['symbol']),
                'name' => $row['name'] ?? null,
                'exchange' => $row['exchangeShortName'] ?? null,
                'last_price' => $row['price'] ?? null,
                'is_active' => true,
            ])
            ->unique('symbol')
            ->values();

        foreach ($rows->chunk(1000) as $chunk) {
            Ticker::upsert($chunk->all(), uniqueBy: ['symbol'], update: ['name', 'exchange', 'last_price']);
        }
    }
}
