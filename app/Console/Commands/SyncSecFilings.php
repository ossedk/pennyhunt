<?php

namespace App\Console\Commands;

use App\Models\SecFiling;
use App\Models\Ticker;
use App\Models\TickerShareCount;
use App\Services\MarketData\EdgarClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pulls the dilution-relevant EDGAR history (shelf registrations, prospectus
 * takedowns, share counts) for every mentioned ticker with a known CIK.
 * Filing dates make every feature point-in-time computable, so one full sync
 * covers both the live engine and historical backtests.
 */
class SyncSecFilings extends Command
{
    protected $signature = 'pennyhunt:sync-sec-filings
        {--months=24 : Only tickers mentioned within this window}
        {--min-mentions=2 : Only tickers with at least this many mentions}
        {--limit=0 : Cap tickers processed this run (0 = all)}
        {--skip-synced-days=7 : Skip tickers synced within N days (0 = resync all)}';

    protected $description = 'Sync SEC EDGAR filings + shares outstanding for mentioned tickers';

    /** Forms worth storing (dilution / solvency relevant). */
    protected const FORMS = [
        ...SecFiling::SHELF_FORMS,
        ...SecFiling::TAKEDOWN_FORMS,
        'S-1', 'S-1/A', 'F-1', 'F-1/A', '8-K', '10-K', '10-Q', '20-F', 'NT 10-K', 'NT 10-Q',
    ];

    public function handle(EdgarClient $edgar): int
    {
        $tickerIds = DB::table('post_ticker_mentions')
            ->select('ticker_id')
            ->where('posted_at', '>=', now()->subMonths((int) $this->option('months')))
            ->groupBy('ticker_id')
            ->havingRaw('COUNT(*) >= ?', [(int) $this->option('min-mentions')])
            ->pluck('ticker_id');

        $skipDays = (int) $this->option('skip-synced-days');

        $tickers = Ticker::query()
            ->whereIn('id', $tickerIds)
            ->whereNotNull('cik')
            ->when($skipDays > 0, fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('meta->edgar_synced_at')
                ->orWhere('meta->edgar_synced_at', '<', now()->subDays($skipDays)->toIso8601String())))
            ->orderBy('symbol')
            ->when((int) $this->option('limit') > 0, fn ($q) => $q->limit((int) $this->option('limit')))
            ->get();

        $this->info("Syncing EDGAR data for {$tickers->count()} tickers");

        foreach ($tickers as $i => $ticker) {
            [$filings, $shares] = $this->syncTicker($edgar, $ticker);

            $this->output->write("\r  {$ticker->symbol}: {$filings} filings, {$shares} share counts  [".($i + 1)."/{$tickers->count()}]   ");
        }

        $this->output->writeln('');
        $this->info('Done.');

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} stored filing / share-count rows */
    protected function syncTicker(EdgarClient $edgar, Ticker $ticker): array
    {
        $now = now();

        $submissions = $edgar->submissions((int) $ticker->cik);

        // SIC industry code rides along free — the sector bucket for the
        // sympathy-play (sector heat) features.
        if ($submissions['sic'] !== null && $ticker->sic_code !== $submissions['sic']) {
            $ticker->forceFill(['sic_code' => $submissions['sic']])->save();
        }

        $filings = collect($submissions['filings'])
            ->filter(fn (array $f): bool => in_array($f['form'], self::FORMS, true))
            ->map(fn (array $f): array => [
                'ticker_id' => $ticker->id,
                'form' => $f['form'],
                'filed_at' => $f['filed_at'],
                'accession' => $f['accession'],
                'created_at' => $now,
            ])
            ->unique('accession')
            ->values();

        foreach ($filings->chunk(500) as $chunk) {
            SecFiling::upsert($chunk->all(), uniqueBy: ['ticker_id', 'accession'], update: ['form', 'filed_at']);
        }

        $shareRows = collect($edgar->sharesOutstanding((int) $ticker->cik))
            ->map(fn (int $shares, string $asOf): array => [
                'ticker_id' => $ticker->id,
                'as_of' => $asOf,
                'shares' => $shares,
                'created_at' => $now,
            ])
            ->values();

        foreach ($shareRows->chunk(500) as $chunk) {
            TickerShareCount::upsert($chunk->all(), uniqueBy: ['ticker_id', 'as_of'], update: ['shares']);
        }

        $ticker->forceFill(['meta' => [...($ticker->meta ?? []), 'edgar_synced_at' => $now->toIso8601String()]])->save();

        return [$filings->count(), $shareRows->count()];
    }
}
