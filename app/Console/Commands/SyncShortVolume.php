<?php

namespace App\Console\Commands;

use App\Models\ShortVolume;
use App\Services\MarketData\RegShoClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Ingests FINRA Reg SHO daily short-volume files. Rows are stored only for
 * tickers in our universe, so 24 months of history stays bounded. Days that
 * already have rows are skipped, making incremental nightly runs cheap and
 * long backfills resumable.
 */
class SyncShortVolume extends Command
{
    protected $signature = 'pennyhunt:sync-short-volume
        {--days=5 : How many calendar days back to fetch}
        {--force : Refetch days that already have rows}';

    protected $description = 'Sync FINRA Reg SHO daily short-sale volume (free, keyless)';

    public function handle(RegShoClient $regSho): int
    {
        $symbols = DB::table('tickers')->pluck('id', 'symbol');

        $existingDays = $this->option('force')
            ? collect()
            : DB::table('short_volumes')->select('day')->distinct()->pluck('day')
                ->map(fn ($d) => substr((string) $d, 0, 10))->flip();

        $day = CarbonImmutable::now('America/New_York')->startOfDay();
        $stored = 0;
        $fetched = 0;

        for ($i = 0; $i < (int) $this->option('days'); $i++, $day = $day->subDay()) {
            if ($day->isWeekend() || $existingDays->has($day->toDateString())) {
                continue;
            }

            $rows = $regSho->dailyShortVolume($day->format('Ymd'));
            $fetched++;

            if ($rows === []) {
                continue; // holiday
            }

            $stored += $this->store($day->toDateString(), $rows, $symbols);

            $this->output->write("\r  {$day->toDateString()}: {$stored} rows stored so far   ");

            Sleep::for(150)->milliseconds();
        }

        $this->output->writeln('');
        $this->info("Done. {$fetched} files fetched, {$stored} rows stored.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{short_volume: float, total_volume: float}>  $rows
     * @param  \Illuminate\Support\Collection<string, int>  $symbols
     */
    protected function store(string $date, array $rows, $symbols): int
    {
        $now = now();
        $inserts = [];

        foreach ($rows as $symbol => $volumes) {
            $tickerId = $symbols[$symbol] ?? null;

            if ($tickerId === null || $volumes['total_volume'] <= 0) {
                continue;
            }

            $inserts[] = [
                'ticker_id' => $tickerId,
                'day' => $date,
                'short_volume' => $volumes['short_volume'],
                'total_volume' => $volumes['total_volume'],
                'short_ratio' => round($volumes['short_volume'] / $volumes['total_volume'], 4),
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($inserts, 1000) as $chunk) {
            ShortVolume::upsert($chunk, uniqueBy: ['ticker_id', 'day'], update: ['short_volume', 'total_volume', 'short_ratio']);
        }

        return count($inserts);
    }
}
