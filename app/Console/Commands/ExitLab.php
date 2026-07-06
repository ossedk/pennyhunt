<?php

namespace App\Console\Commands;

use App\Services\Backtesting\ExitSimulator;
use App\Services\Backtesting\FrictionModel;
use App\Support\AnalyticsGate;
use App\Support\Memory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The Exit Lab: re-simulates a backtest run's FIRED trades under a grid of
 * exit disciplines — seconds per config instead of a 45-minute backtest.
 * Same events, same entries; only the exit rules differ, so differences
 * between rows are pure exit-rule effect.
 *
 * Overfitting guards: rules carry physical rationale (ATR = the stock's own
 * noise floor), the grid is small, and every config reports a first-half /
 * second-half split — a rule that only works in one half is curve fit.
 */
class ExitLab extends Command
{
    protected $signature = 'pennyhunt:exit-lab
        {--run= : Backtest run id (default: latest done)}
        {--all-candidates : Trade ALL candidate events passing the filters, not just composite-fired ones (model-first mode)}
        {--tier= : Only events with walk-forward confidence >= this (e.g. 0.13)}
        {--max-tier= : Only events with walk-forward confidence below this}
        {--class= : Only this classification (prediction = pre-run <= 15%, reaction = chasing)}
        {--max-prerun= : Only events with pre_return_3d at/below this fraction}
        {--max-price= : Only events with entry at/below this price}
        {--min-smallcap-rel= : Regime throttle: require smallcap_rel_20d >= this (e.g. -0.02)}
        {--min-moonshot= : Only events with moonshot_confidence >= this}
        {--min-meta= : Only events with meta_confidence >= this}
        {--cooldown=10 : Per-ticker sessions between taken trades (candidate mode)}';

    protected $description = 'Grid-test exit disciplines over a run\'s fired trades';

    /** @return array<string, array<string, mixed>> */
    protected function grid(): array
    {
        return [
            'legacy (10% stop, 5d)' => ['stop_loss' => 0.10, 'max_hold' => 5],
            'NO STOP, 5d hold' => ['max_hold' => 5],
            'NO STOP, 10d hold' => ['max_hold' => 10],
            'atr 1.5x, 5d' => ['atr_stop_mult' => 1.5, 'max_hold' => 5],
            'atr 2.0x, 5d' => ['atr_stop_mult' => 2.0, 'max_hold' => 5],
            'close-stop 15%, 5d' => ['stop_loss' => 0.15, 'stop_on_close' => true, 'max_hold' => 5],
            'close-stop atr 2x, 5d' => ['atr_stop_mult' => 2.0, 'stop_on_close' => true, 'max_hold' => 5],
            'atr 2x + trail 2.5x, 10d' => ['atr_stop_mult' => 2.0, 'trail_atr_mult' => 2.5, 'max_hold' => 10],
            'atr 2x + partial@30 + trail' => ['atr_stop_mult' => 2.0, 'partial_take_at' => 0.30, 'trail_atr_mult' => 2.5, 'max_hold' => 10],
            'no stop + partial@30 + trail' => ['partial_take_at' => 0.30, 'trail_atr_mult' => 2.5, 'max_hold' => 10],
            'atr 2x + collapse 25%' => ['atr_stop_mult' => 2.0, 'mention_collapse_frac' => 0.25, 'max_hold' => 10],
            'no stop + collapse 25%, 10d' => ['mention_collapse_frac' => 0.25, 'max_hold' => 10],
            'full stack' => ['atr_stop_mult' => 2.0, 'partial_take_at' => 0.30, 'trail_atr_mult' => 2.5, 'mention_collapse_frac' => 0.25, 'max_hold' => 10],
            'full + gap veto 15%' => ['atr_stop_mult' => 2.0, 'partial_take_at' => 0.30, 'trail_atr_mult' => 2.5, 'mention_collapse_frac' => 0.25, 'max_entry_gap' => 0.15, 'max_hold' => 10],
            'closestop2x+partial+collapse' => ['atr_stop_mult' => 2.0, 'stop_on_close' => true, 'partial_take_at' => 0.30, 'trail_atr_mult' => 2.5, 'mention_collapse_frac' => 0.25, 'max_entry_gap' => 0.15, 'max_hold' => 10],
        ];
    }

    public function handle(ExitSimulator $simulator): int
    {
        Memory::raise('2048M');

        $runId = $this->option('run')
            ? (int) $this->option('run')
            : (int) DB::table('backtest_runs')->where('status', 'done')->orderByDesc('id')->value('id');

        $events = DB::table('backtest_events')
            ->where('backtest_run_id', $runId)
            // Model-first mode trades every candidate event that passes the
            // filters — the composite gate (fired) is bypassed entirely.
            ->when(! $this->option('all-candidates'), fn ($q) => $q->where('fired', true))
            // Tier slicing prefers the GBM walk-forward score (out-of-sample);
            // logistic confidence is the fallback for events that predate it.
            ->when($this->option('tier') !== null, fn ($q) => $q->whereRaw(
                'COALESCE(gbm_confidence, confidence) >= ?',
                [(float) $this->option('tier')],
            ))
            ->when($this->option('max-tier') !== null, fn ($q) => $q->whereRaw(
                'COALESCE(gbm_confidence, confidence) < ?',
                [(float) $this->option('max-tier')],
            ))
            ->when($this->option('class') !== null, fn ($q) => $q->where('classification', $this->option('class')))
            ->when($this->option('max-prerun') !== null, fn ($q) => $q->where('pre_return_3d', '<=', (float) $this->option('max-prerun')))
            ->when($this->option('max-price') !== null, fn ($q) => $q->where('entry', '<=', (float) $this->option('max-price')))
            ->when($this->option('min-smallcap-rel') !== null, fn ($q) => $q->where('smallcap_rel_20d', '>=', (float) $this->option('min-smallcap-rel')))
            ->when($this->option('min-moonshot') !== null, fn ($q) => $q->where('moonshot_confidence', '>=', (float) $this->option('min-moonshot')))
            ->when($this->option('min-meta') !== null, fn ($q) => $q->where('meta_confidence', '>=', (float) $this->option('min-meta')))
            ->orderBy('day')
            ->get(['id', 'ticker_id', 'day', 'entry_date', 'entry', 'atr_pct', 'dollar_volume', 'mentions']);

        // Per-ticker cooldown: candidate days cluster on runners — entering
        // the same ticker on consecutive days pseudo-replicates one move.
        // Keep the first event, skip re-entries inside the cooldown window.
        $cooldownDays = max((int) $this->option('cooldown'), 0);

        if ($cooldownDays > 0) {
            $lastTaken = [];
            $events = $events->filter(function ($event) use (&$lastTaken, $cooldownDays): bool {
                $last = $lastTaken[$event->ticker_id] ?? null;

                if ($last !== null && strtotime($event->day.' UTC') - strtotime($last.' UTC') < $cooldownDays * 1.45 * 86400) {
                    return false; // ~cooldown sessions in calendar days
                }

                $lastTaken[$event->ticker_id] = $event->day;

                return true;
            })->values();
        }

        if ($events->isEmpty()) {
            $this->error("Run #{$runId}: no events pass the filters.");

            return self::FAILURE;
        }

        $mode = $this->option('all-candidates') ? 'candidate events (model-first)' : 'fired trades';
        $this->info("Run #{$runId}: ".$events->count()." {$mode}".($this->option('tier') ? " (tier ≥ {$this->option('tier')})" : '').'.');

        [$barsByTicker, $signalCloses] = $this->loadBars($events);
        $mentionSeries = $this->loadMentions($events);

        $midpoint = $events[intdiv($events->count(), 2)]->day;

        $rows = [];

        foreach ($this->grid() as $label => $config) {
            $outcomes = [];

            foreach ($events as $event) {
                $bars = $this->window($barsByTicker[$event->ticker_id] ?? [], $event->entry_date, 11);

                // Entry comes from the CURRENT bar series (first window bar's
                // open — the backtester's own definition), not the stored
                // value: bars get split-adjusted after the run (SRXH's
                // reverse split turned a stored 0.18 entry into 10.00 bars
                // and fabricated +181,300%). One price basis, no mixing.
                if (count($bars) < 3 || $bars[0]['date'] !== $event->entry_date || $bars[0]['open'] <= 0) {
                    continue;
                }

                // Data-break guard: a >4x (or <0.25x) overnight jump inside
                // the window is a split-adjustment seam (SMCI's 10:1 mid-
                // series), not a trade. Skipping loses the rare true 4x
                // overnight gap — a price worth paying to never train
                // discipline choices on fabricated returns.
                if ($this->hasSeriesBreak($bars)) {
                    continue;
                }

                $result = $simulator->simulate(
                    $config,
                    $bars[0]['open'],
                    $signalCloses[$event->ticker_id][$event->day] ?? 0.0,
                    $event->atr_pct !== null ? min((float) $event->atr_pct, 1.0) : null,
                    $bars,
                    $this->mentionOffsets($mentionSeries[$event->ticker_id] ?? [], $event->day, $bars),
                );

                if ($result['skipped']) {
                    continue;
                }

                $outcomes[] = [
                    'return' => $result['return'],
                    'reason' => $result['reason'],
                    'net_flat' => $result['return'] - 0.05,
                    'net_tiered' => $result['return'] - FrictionModel::roundTrip((float) $event->entry, $event->dollar_volume !== null ? (float) $event->dollar_volume : null),
                    'half' => $event->day < $midpoint ? 1 : 2,
                ];
            }

            $rows[] = [$label, ...$this->metrics($outcomes)];
        }

        $this->table(
            ['config', 'n', 'avg exit', 'net (flat 5%)', 'net (tiered)', 'PF net', 'stop%', 'trail%', 'collapse%', 'net 1st half', 'net 2nd half'],
            $rows,
        );

        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $outcomes */
    protected function metrics(array $outcomes): array
    {
        if ($outcomes === []) {
            return [0, '—', '—', '—', '—', '—', '—', '—', '—', '—'];
        }

        $n = count($outcomes);
        $avg = fn (string $k): float => array_sum(array_column($outcomes, $k)) / $n;
        $share = fn (string $reason): string => number_format(count(array_filter($outcomes, fn ($o) => $o['reason'] === $reason)) / $n * 100, 0).'%';

        $nets = array_column($outcomes, 'net_tiered');
        $wins = array_sum(array_filter($nets, fn ($v) => $v > 0));
        $losses = abs(array_sum(array_filter($nets, fn ($v) => $v < 0)));

        $halfAvg = function (int $half) use ($outcomes): string {
            $subset = array_filter($outcomes, fn ($o) => $o['half'] === $half);

            return $subset === []
                ? '—'
                : sprintf('%+.1f%%', array_sum(array_column($subset, 'net_tiered')) / count($subset) * 100);
        };

        return [
            $n,
            sprintf('%+.1f%%', $avg('return') * 100),
            sprintf('%+.1f%%', $avg('net_flat') * 100),
            sprintf('%+.1f%%', $avg('net_tiered') * 100),
            $losses > 0 ? number_format($wins / $losses, 2) : '∞',
            $share('stop'),
            $share('trail'),
            $share('mention_collapse'),
            $halfAvg(1),
            $halfAvg(2),
        ];
    }

    /**
     * @return array{0: array<int, array<int, array{date: string, open: float, high: float, low: float, close: float}>>, 1: array<int, array<string, float>>}
     */
    protected function loadBars($events): array
    {
        $bars = [];
        $signalCloses = [];
        $signalDays = [];

        foreach ($events as $event) {
            $signalDays[$event->ticker_id][$event->day] = true;
        }

        DB::table('market_bars')
            ->whereIn('ticker_id', $events->pluck('ticker_id')->unique())
            ->where('interval', '1d')
            ->orderBy('bucket_start')
            ->select('ticker_id', 'bucket_start', 'open', 'high', 'low', 'close')
            ->each(function ($bar) use (&$bars, &$signalCloses, $signalDays): void {
                $date = substr((string) $bar->bucket_start, 0, 10);
                $tickerId = (int) $bar->ticker_id;

                $bars[$tickerId][] = [
                    'date' => $date,
                    'open' => (float) $bar->open,
                    'high' => (float) $bar->high,
                    'low' => (float) $bar->low,
                    'close' => (float) $bar->close,
                ];

                if (isset($signalDays[$tickerId][$date])) {
                    $signalCloses[$tickerId][$date] = (float) $bar->close;
                }
            });

        return [$bars, $signalCloses];
    }

    /** @param array<int, array{open: float, close: float}> $bars */
    protected function hasSeriesBreak(array $bars): bool
    {
        for ($i = 1; $i < count($bars); $i++) {
            $prevClose = $bars[$i - 1]['close'];

            if ($prevClose <= 0) {
                return true;
            }

            $gap = $bars[$i]['open'] / $prevClose;

            if ($gap > 4.0 || $gap < 0.25) {
                return true;
            }
        }

        return false;
    }

    /** Bars from entry_date onward, capped. */
    protected function window(array $bars, string $entryDate, int $count): array
    {
        $out = [];

        foreach ($bars as $bar) {
            if ($bar['date'] < $entryDate) {
                continue;
            }

            $out[] = $bar;

            if (count($out) >= $count) {
                break;
            }
        }

        return $out;
    }

    /** @return array<int, array<string, int>> [tickerId => [date => mentions]] */
    protected function loadMentions($events): array
    {
        $gate = AnalyticsGate::mentionJoin('m');
        $ids = $events->pluck('ticker_id')->unique()->implode(',');

        if ($ids === '') {
            return [];
        }

        $rows = DB::select(<<<SQL
            SELECT m.ticker_id, date(m.posted_at) AS day, COUNT(*) AS mentions
            FROM post_ticker_mentions m
            {$gate}
            WHERE m.ticker_id IN ({$ids})
            GROUP BY m.ticker_id, day
        SQL);

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->ticker_id][(string) $row->day] = (int) $row->mentions;
        }

        return $out;
    }

    /**
     * Maps calendar-daily mention counts onto session offsets: each bar
     * carries the mentions since the previous bar (weekend buzz lands on
     * Monday). Offset -1 = the fire day itself.
     *
     * @param  array<string, int>  $daily
     * @param  array<int, array{date: string}>  $bars
     * @return array<int, int>
     */
    protected function mentionOffsets(array $daily, string $fireDay, array $bars): array
    {
        $out = [-1 => $daily[$fireDay] ?? 0];
        $prev = $fireDay;

        foreach ($bars as $offset => $bar) {
            $sum = 0;

            for ($ts = strtotime($prev.' UTC') + 86400; $ts <= strtotime($bar['date'].' UTC'); $ts += 86400) {
                $sum += $daily[gmdate('Y-m-d', $ts)] ?? 0;
            }

            $out[$offset] = $sum;
            $prev = $bar['date'];
        }

        return $out;
    }
}
