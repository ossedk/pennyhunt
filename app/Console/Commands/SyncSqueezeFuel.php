<?php

namespace App\Console\Commands;

use App\Models\Ticker;
use App\Services\MarketData\SqueezeFuelClients;
use App\Support\Memory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Squeeze-fuel sync (all free feeds): FINRA bi-monthly short interest,
 * SEC fails-to-deliver, iBorrowDesk borrow fee/availability. The moonshot
 * mechanism in penny land is supply — these three measure it directly.
 *
 * Nightly: pulls the current month's SI window + latest two FTD periods +
 * borrow for actively-mentioned tickers. --backfill-months walks history
 * (SI + FTD only; borrow history is capped by iBorrowDesk's own depth).
 */
class SyncSqueezeFuel extends Command
{
    protected $signature = 'pennyhunt:sync-squeeze-fuel
        {--backfill-months=0 : Walk SI + FTD history this many months back}
        {--skip-borrow : Skip the per-ticker iBorrowDesk pulls}';

    protected $description = 'Sync FINRA short interest, SEC FTDs and iBorrowDesk borrow data';

    public function handle(SqueezeFuelClients $clients): int
    {
        Memory::raise('2048M');

        $symbolMap = Ticker::query()->pluck('id', 'symbol')->all();

        $months = max((int) $this->option('backfill-months'), 1);

        $this->syncShortInterest($clients, $symbolMap, $months);
        $this->syncFtds($clients, $symbolMap, $months);

        if (! $this->option('skip-borrow')) {
            $this->syncBorrow($clients);
        }

        return self::SUCCESS;
    }

    /** @param array<string, int> $symbolMap */
    protected function syncShortInterest(SqueezeFuelClients $clients, array $symbolMap, int $months): void
    {
        $this->info("Short interest: {$months} month(s), two settlements each...");

        for ($m = 0; $m < $months; $m++) {
            $anchor = now()->subMonths($m);

            // Two settlement periods per month: mid-month (~15th) and
            // month-end. Exact dates shift around weekends/holidays —
            // probe backwards from each anchor until data answers.
            foreach (['mid', 'eom'] as $half) {
                $base = $half === 'mid' ? $anchor->copy()->day(15) : $anchor->copy()->endOfMonth();

                $candidates = [];

                for ($d = 0; $d <= 4; $d++) {
                    $candidates[] = $base->copy()->subDays($d)->toDateString();
                }

                $settlement = $clients->finraProbeSettlementDate($candidates);

                if ($settlement === null) {
                    continue; // not published yet (current period) or holiday cluster
                }

                if (DB::table('short_interest')->where('settlement_date', $settlement)->exists()) {
                    continue; // already loaded
                }

                $rows = collect($clients->finraShortInterest($settlement))
                    ->filter(fn (array $r): bool => isset($symbolMap[$r['symbol']]))
                    ->map(fn (array $r): array => [
                        'ticker_id' => $symbolMap[$r['symbol']],
                        'settlement_date' => $r['settlement_date'],
                        'shares_short' => $r['shares_short'],
                        'days_to_cover' => $r['days_to_cover'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                    ->unique(fn (array $r): string => $r['ticker_id'].'|'.$r['settlement_date'])
                    ->values();

                foreach ($rows->chunk(1000) as $chunk) {
                    DB::table('short_interest')->upsert(
                        $chunk->all(),
                        ['ticker_id', 'settlement_date'],
                        ['shares_short', 'days_to_cover', 'updated_at'],
                    );
                }

                $this->line("  {$settlement}: ".$rows->count().' rows');
            }
        }
    }

    /** @param array<string, int> $symbolMap */
    protected function syncFtds(SqueezeFuelClients $clients, array $symbolMap, int $months): void
    {
        // Periods newest-first: current month may not be published yet —
        // missing files return empty and cost one request.
        $periods = [];

        for ($m = 0; $m <= $months; $m++) {
            $ym = now()->subMonths($m)->format('Ym');
            $periods[] = "{$ym}b";
            $periods[] = "{$ym}a";
        }

        $this->info('FTDs: '.count($periods).' half-month files...');

        foreach ($periods as $period) {
            $rows = collect($clients->secFailsToDeliver($period))
                ->filter(fn (array $r): bool => isset($symbolMap[$r['symbol']]) && $r['fails'] > 0)
                // One row per (ticker, settlement day) — keep the max.
                ->groupBy(fn (array $r): string => $r['symbol'].'|'.$r['settlement_date'])
                ->map(fn ($group) => $group->sortByDesc('fails')->first())
                ->values()
                ->map(fn (array $r): array => [
                    'ticker_id' => $symbolMap[$r['symbol']],
                    'settlement_date' => $r['settlement_date'],
                    'fails_quantity' => $r['fails'],
                    'price' => $r['price'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            foreach ($rows->chunk(1000) as $chunk) {
                DB::table('ftd_reports')->upsert(
                    $chunk->all(),
                    ['ticker_id', 'settlement_date'],
                    ['fails_quantity', 'price', 'updated_at'],
                );
            }

            $this->line("  {$period}: ".$rows->count().' rows');
            Sleep::for(300)->milliseconds(); // SEC fair access
        }
    }

    protected function syncBorrow(SqueezeFuelClients $clients): void
    {
        // Actively-discussed tickers only — iBorrowDesk is an unofficial
        // mirror; keep the footprint polite.
        $tickers = Ticker::query()
            ->whereIn('id', DB::table('post_ticker_mentions')
                ->select('ticker_id')
                ->where('posted_at', '>=', now()->subDays(14))
                ->groupBy('ticker_id')
                ->havingRaw('COUNT(*) >= 3')
                ->pluck('ticker_id'))
            ->where('is_active', true)
            ->limit(400)
            ->get(['id', 'symbol']);

        $this->info("Borrow rates: {$tickers->count()} tickers...");

        $stored = 0;

        foreach ($tickers as $ticker) {
            $rows = collect($clients->iborrowDesk($ticker->symbol))
                ->map(fn (array $r): array => [
                    'ticker_id' => $ticker->id,
                    'day' => $r['day'],
                    'fee' => $r['fee'],
                    'available' => $r['available'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            foreach ($rows->chunk(500) as $chunk) {
                DB::table('borrow_rates')->upsert($chunk->all(), ['ticker_id', 'day'], ['fee', 'available', 'updated_at']);
            }

            $stored += $rows->count();
            Sleep::for(250)->milliseconds();
        }

        $this->line("  {$stored} borrow rows upserted.");
    }
}
