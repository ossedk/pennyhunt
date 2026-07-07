<?php

namespace App\Console\Commands;

use App\Models\BacktestEvent;
use App\Services\Features\Day0Features;
use App\Services\MarketData\PolygonClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Backfills day-0 microstructure features (first-30-minute opening range,
 * VWAP hold, volume share, gap fade) onto backtest events from Polygon
 * minute aggregates. One API call per ticker-day, so the pool is targeted:
 * events that could plausibly trade (moonshot score or fired), not all 26k.
 */
class BackfillDay0Features extends Command
{
    protected $signature = 'pennyhunt:backfill-day0
        {--run= : Backtest run id (default: latest done)}
        {--min-moonshot=0.03 : Include events with moonshot_confidence >= this}
        {--entry-day : Backfill the ENTRY session opening range instead of the signal day}
        {--limit=0 : Cap ticker-days processed this run (0 = all)}';

    protected $description = 'Backfill first-30-minute microstructure features onto tradeable backtest events';

    public function handle(PolygonClient $polygon): int
    {
        if (! $polygon->enabled()) {
            $this->error('Polygon API key not configured.');

            return self::FAILURE;
        }

        $runId = $this->option('run')
            ? (int) $this->option('run')
            : (int) DB::table('backtest_runs')->where('status', 'done')->orderByDesc('id')->value('id');

        $entryDay = (bool) $this->option('entry-day');

        $events = BacktestEvent::query()
            ->where('backtest_run_id', $runId)
            ->whereNull($entryDay ? 'entry_or_return' : 'or_return_30m')
            ->when($entryDay, fn ($q) => $q->whereNotNull('entry_date'))
            ->where(fn ($q) => $q
                ->where('fired', true)
                ->orWhere('moonshot_confidence', '>=', (float) $this->option('min-moonshot')))
            ->orderBy('day')
            ->when((int) $this->option('limit') > 0, fn ($q) => $q->limit((int) $this->option('limit')))
            ->get(['id', 'ticker_id', 'symbol', 'day', 'entry_date']);

        $this->info("Run #{$runId}: ".$events->count().' events need '.($entryDay ? 'entry-day OR' : 'day-0').' features.');

        // Prior close + trailing avg volume per (ticker, day) from daily bars.
        $done = 0;

        foreach ($events as $event) {
            $targetDay = $entryDay ? $event->entry_date->toDateString() : $event->day->toDateString();
            $context = $this->dailyContext($event->ticker_id, $targetDay);
            $minutes = $polygon->minuteBars($event->symbol, $targetDay);

            $features = Day0Features::compute($minutes, $context['prev_close'], $context['avg_volume']);

            BacktestEvent::query()->whereKey($event->id)->update($entryDay
                ? [
                    'entry_or_return' => $features['or_return_30m'],
                    'entry_vwap_dist' => $features['vwap_dist_30m'],
                    'entry_gap_faded' => $features['gap_faded'],
                ]
                : [
                    'or_return_30m' => $features['or_return_30m'],
                    'vwap_dist_30m' => $features['vwap_dist_30m'],
                    'or_vol_share' => $features['or_vol_share'],
                    'gap_faded' => $features['gap_faded'],
                ]);

            $done++;
            $this->output->write("\r  {$done}/{$events->count()}   ");
            Sleep::for(60)->milliseconds();
        }

        $this->output->writeln('');
        $this->info("Done — {$done} events updated.");

        return self::SUCCESS;
    }

    /** @return array{prev_close: ?float, avg_volume: ?float} */
    protected function dailyContext(int $tickerId, string $day): array
    {
        $bars = DB::table('market_bars')
            ->where('ticker_id', $tickerId)
            ->where('interval', '1d')
            ->where('bucket_start', '<', $day.' 00:00:00')
            ->orderByDesc('bucket_start')
            ->limit(20)
            ->get(['close', 'volume']);

        return [
            'prev_close' => $bars->first() !== null ? (float) $bars->first()->close : null,
            'avg_volume' => $bars->count() >= 5 ? (float) $bars->avg('volume') : null,
        ];
    }
}
