<?php

namespace App\Console\Commands;

use App\Models\Ticker;
use App\Services\MarketData\YahooMarketData;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Pulls daily OHLC bars (Yahoo, keyless) for every ticker that has forum
 * mentions in the window, so the backtester can price entries and outcomes.
 */
class SyncMarketBars extends Command
{
    protected $signature = 'pennyhunt:sync-market-bars
        {--months=7 : Bar history to fetch (should cover backfill + forward windows)}
        {--min-mentions=3 : Only tickers with at least this many mentions}
        {--missing-only : Skip tickers that already have bars}';

    protected $description = 'Sync daily market bars for mentioned tickers (Yahoo, no key needed)';

    public function handle(YahooMarketData $marketData): int
    {
        $from = CarbonImmutable::now()->subMonths((int) $this->option('months'))->startOfDay();
        $to = CarbonImmutable::now();

        $tickerIds = DB::table('post_ticker_mentions')
            ->select('ticker_id')
            ->where('posted_at', '>=', $from)
            ->groupBy('ticker_id')
            ->havingRaw('COUNT(*) >= ?', [(int) $this->option('min-mentions')])
            ->pluck('ticker_id');

        $tickers = Ticker::query()
            ->whereIn('id', $tickerIds)
            ->when($this->option('missing-only'), fn ($q) => $q->whereNotIn(
                'id',
                DB::table('market_bars')->select('ticker_id')->distinct(),
            ))
            ->orderBy('symbol')
            ->get();

        // Regime benchmark (IWM) + macro series (VIX, BTC) always ride along —
        // MarketIntelligence needs their bars even though they're never mentioned.
        $context = [
            config('pennyhunt.benchmark_symbol', 'IWM') => 'Regime benchmark ETF',
            ...array_combine(
                array_values(config('pennyhunt.macro_symbols', [])),
                array_fill(0, count(config('pennyhunt.macro_symbols', [])), 'Macro context series'),
            ),
        ];

        foreach ($context as $symbol => $name) {
            $contextTicker = Ticker::firstOrCreate(
                ['symbol' => $symbol],
                ['name' => $name, 'is_active' => true, 'is_ambiguous' => true],
            );

            if (! $tickers->contains('id', $contextTicker->id)) {
                $tickers->push($contextTicker);
            }
        }

        $this->info("Syncing {$tickers->count()} tickers ({$from->toDateString()} -> {$to->toDateString()})");

        $ok = 0;
        $empty = 0;

        $failed = 0;

        foreach ($tickers as $i => $ticker) {
            // A transient network error on one ticker must not kill a
            // multi-hour run: retry once after a breather, then skip.
            try {
                $stored = $marketData->syncDailyBars($ticker, $from, $to);
            } catch (\Throwable) {
                Sleep::for(15)->seconds();

                try {
                    $stored = $marketData->syncDailyBars($ticker, $from, $to);
                } catch (\Throwable $e) {
                    $failed++;
                    $this->output->writeln("\n  {$ticker->symbol}: FAILED ({$e->getMessage()})");

                    continue;
                }
            }

            $stored > 0 ? $ok++ : $empty++;

            $this->output->write("\r  {$ticker->symbol}: {$stored} bars  [".($i + 1)."/{$tickers->count()}]   ");

            Sleep::for(300)->milliseconds(); // stay polite with Yahoo
        }

        $this->output->writeln('');
        $this->info("Done. {$ok} tickers with bars, {$empty} without (delisted/unknown to Yahoo), {$failed} failed.");

        return self::SUCCESS;
    }
}
