<?php

namespace App\Console\Commands;

use App\Models\InsiderTrade;
use App\Models\Ticker;
use App\Services\MarketData\EdgarClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Form 4 insider transactions (the bullish side of EDGAR): open-market
 * purchases by officers/directors in sub-$5 names are one of the strongest
 * known penny-stock signals. Filing dates keep every derived feature
 * point-in-time computable.
 *
 * One submissions request per ticker + one request per unseen Form 4, so the
 * nightly delta is small once the initial backfill has run.
 */
class SyncInsiderTrades extends Command
{
    protected $signature = 'pennyhunt:sync-insider-trades
        {--months=24 : Only tickers mentioned within this window}
        {--min-mentions=2 : Only tickers with at least this many mentions}
        {--limit=0 : Cap tickers processed this run (0 = all)}
        {--max-filings=40 : Most-recent Form 4s fetched per ticker}
        {--skip-synced-days=7 : Skip tickers synced within N days (0 = resync all)}';

    protected $description = 'Sync SEC Form 4 insider purchases/sales for mentioned tickers';

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
                ->whereNull('meta->form4_synced_at')
                ->orWhere('meta->form4_synced_at', '<', now()->subDays($skipDays)->toIso8601String())))
            ->orderBy('symbol')
            ->when((int) $this->option('limit') > 0, fn ($q) => $q->limit((int) $this->option('limit')))
            ->get();

        $this->info("Syncing Form 4 data for {$tickers->count()} tickers");

        $totalStored = 0;
        $failed = 0;

        foreach ($tickers as $i => $ticker) {
            // One bad filing (numeric overflow, malformed XML) must never
            // kill a multi-hour backfill — log, skip, continue.
            try {
                $stored = $this->syncTicker($edgar, $ticker);
                $totalStored += $stored;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning("Form 4 sync failed for {$ticker->symbol}: {$e->getMessage()}");

                continue;
            }

            $this->output->write("\r  {$ticker->symbol}: {$stored} txns  [".($i + 1)."/{$tickers->count()}]   ");
        }

        if ($failed > 0) {
            $this->output->writeln('');
            $this->warn("{$failed} tickers failed (see log) — they retry next run.");
        }

        $this->output->writeln('');
        $this->info("Done. {$totalStored} transactions stored.");

        return self::SUCCESS;
    }

    protected function syncTicker(EdgarClient $edgar, Ticker $ticker): int
    {
        $cutoff = now()->subMonths((int) $this->option('months'))->toDateString();

        $form4s = collect($edgar->submissions((int) $ticker->cik)['filings'])
            ->filter(fn (array $f): bool => in_array($f['form'], ['4', '4/A'], true)
                && $f['filed_at'] >= $cutoff
                && $f['primary_document'] !== null
                && str_ends_with(strtolower($f['primary_document']), '.xml'))
            ->take((int) $this->option('max-filings'));

        // Only unseen accessions cost a request.
        $known = InsiderTrade::query()
            ->where('ticker_id', $ticker->id)
            ->whereIn('accession', $form4s->pluck('accession'))
            ->pluck('accession')
            ->flip();

        $stored = 0;

        foreach ($form4s as $filing) {
            if (isset($known[$filing['accession']])) {
                continue;
            }

            foreach ($edgar->form4Transactions((int) $ticker->cik, $filing['accession'], $filing['primary_document']) as $txn) {
                // Clamp against garbage filings: shares/price occasionally
                // carry absurd values that overflow the decimal columns.
                $shares = $txn['shares'] !== null ? min($txn['shares'], 99_999_999_999_999.0) : null;
                $price = $txn['price'] !== null ? min($txn['price'], 99_999_999.0) : null;

                InsiderTrade::query()->upsert([[
                    'ticker_id' => $ticker->id,
                    'accession' => $filing['accession'],
                    'seq' => $txn['seq'],
                    'filed_at' => $filing['filed_at'],
                    'transacted_at' => $txn['transacted_at'],
                    'owner_name' => $txn['owner_name'],
                    'is_officer' => $txn['is_officer'],
                    'is_director' => $txn['is_director'],
                    'code' => $txn['code'],
                    'shares' => $shares,
                    'price' => $price,
                    'value' => $shares !== null && $price !== null
                        ? min(round($shares * $price, 2), 99_999_999_999_999.0)
                        : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]], uniqueBy: ['accession', 'seq'], update: ['shares', 'price', 'value']);

                $stored++;
            }
        }

        $ticker->forceFill(['meta' => [...($ticker->meta ?? []), 'form4_synced_at' => now()->toIso8601String()]])->save();

        return $stored;
    }
}
