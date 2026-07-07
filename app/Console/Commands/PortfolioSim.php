<?php

namespace App\Console\Commands;

use App\Services\Backtesting\ExitSimulator;
use App\Services\Backtesting\FrictionModel;
use App\Support\AnalyticsGate;
use App\Support\Memory;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Account-level P&L simulation over a validated cell: walks the cell's
 * trades chronologically compounding a starting account, under either
 * slot sizing (equity / K per concurrent position) or fixed-risk ticket
 * sizing (equity × t per trade, unlimited concurrency, cash-bounded).
 *
 * The moonshot lesson this exists to encode: a 1-in-N lottery profile
 * dies under concentrated slots and survives under small tickets.
 */
class PortfolioSim extends Command
{
    protected $signature = 'pennyhunt:portfolio-sim
        {--run= : Backtest run id (default: latest done)}
        {--cell=moonshot : moonshot (band gate) | phase_e (tier + no-chase)}
        {--equity=100000 : Starting account}';

    protected $description = 'Simulate account P&L for a validated cell under slot vs ticket sizing';

    public function handle(ExitSimulator $simulator): int
    {
        Memory::raise('2048M');

        $runId = $this->option('run')
            ? (int) $this->option('run')
            : (int) DB::table('backtest_runs')->where('status', 'done')->orderByDesc('id')->value('id');

        [$events, $exitConfig, $label] = $this->cell($runId, (string) $this->option('cell'));

        $this->info("Run #{$runId} — {$label}: ".$events->count().' candidate trades.');

        $trades = $this->simulateTrades($simulator, $events, $exitConfig);

        if ($trades === []) {
            $this->error('No simulatable trades.');

            return self::FAILURE;
        }

        $nets = array_column($trades, 'net');
        sort($nets);
        $this->line(sprintf(
            '  per-trade: n=%d, avg %+.1f%%, median %+.1f%%, best %+.1f%%, worst %+.1f%%',
            count($nets),
            array_sum($nets) / count($nets) * 100,
            $nets[intdiv(count($nets), 2)] * 100,
            end($nets) * 100,
            $nets[0] * 100,
        ));

        $start = (float) $this->option('equity');
        $rows = [];

        foreach ([1, 3, 5] as $slots) {
            $result = $this->slotSizing($trades, $start, $slots);
            $rows[] = ["slots={$slots} (".round(100 / $slots).'% each)', ...$result];
        }

        foreach ([0.02, 0.03, 0.05, 0.10] as $ticket) {
            $result = $this->ticketSizing($trades, $start, $ticket);
            $rows[] = ['ticket '.($ticket * 100).'%', ...$result];
        }

        $this->table(['sizing', 'final equity', 'PnL', 'taken', 'skipped', 'max DD'], $rows);

        return self::SUCCESS;
    }

    /** @return array{0: Collection, 1: array<string, mixed>, 2: string} */
    protected function cell(int $runId, string $cell): array
    {
        if ($cell === 'phase_e') {
            $events = DB::table('backtest_events')
                ->where('backtest_run_id', $runId)
                ->where('fired', true)
                ->whereRaw('COALESCE(gbm_confidence, confidence) >= 0.15')
                ->where('pre_return_3d', '<=', 0.15)
                ->orderBy('day')
                ->get(['id', 'ticker_id', 'symbol', 'day', 'entry_date', 'entry', 'dollar_volume']);

            return [$this->cooldown($events), ['mention_collapse_frac' => 0.25, 'max_hold' => 10], 'phase-E cell (composite-fired, tier, no-chase)'];
        }

        $events = DB::table('backtest_events')
            ->where('backtest_run_id', $runId)
            ->where('moonshot_confidence', '>=', config('pennyhunt.moonshot.min_p'))
            ->where('moonshot_confidence', '<', config('pennyhunt.moonshot.max_p'))
            ->where('pre_return_3d', '<=', config('pennyhunt.moonshot.max_pre_run'))
            ->where('entry', '<=', config('pennyhunt.moonshot.max_entry_price'))
            ->where(fn ($q) => $q->whereNull('smallcap_rel_20d')
                ->orWhere('smallcap_rel_20d', '>=', config('pennyhunt.moonshot.min_smallcap_rel')))
            ->orderBy('day')
            ->get(['id', 'ticker_id', 'symbol', 'day', 'entry_date', 'entry', 'dollar_volume']);

        return [$this->cooldown($events), ['max_hold' => 5], 'moonshot band cell (live gate)'];
    }

    protected function cooldown($events)
    {
        $last = [];

        return $events->filter(function ($e) use (&$last): bool {
            $prev = $last[$e->ticker_id] ?? null;

            if ($prev !== null && strtotime($e->day.' UTC') - strtotime($prev.' UTC') < 14.5 * 86400) {
                return false;
            }

            $last[$e->ticker_id] = $e->day;

            return true;
        })->values();
    }

    /** @return list<array{entry: string, exit: string, net: float}> */
    protected function simulateTrades(ExitSimulator $simulator, $events, array $config): array
    {
        $bars = [];

        DB::table('market_bars')
            ->whereIn('ticker_id', $events->pluck('ticker_id')->unique())
            ->where('interval', '1d')
            ->orderBy('bucket_start')
            ->select('ticker_id', 'bucket_start', 'open', 'high', 'low', 'close')
            ->each(function ($b) use (&$bars): void {
                $bars[(int) $b->ticker_id][] = [
                    'date' => substr((string) $b->bucket_start, 0, 10),
                    'open' => (float) $b->open, 'high' => (float) $b->high,
                    'low' => (float) $b->low, 'close' => (float) $b->close,
                ];
            });

        $mentions = [];

        if (isset($config['mention_collapse_frac'])) {
            $gate = AnalyticsGate::mentionJoin('m');
            $ids = $events->pluck('ticker_id')->unique()->implode(',');

            foreach (DB::select("SELECT m.ticker_id, date(m.posted_at) AS day, COUNT(*) AS mentions FROM post_ticker_mentions m {$gate} WHERE m.ticker_id IN ({$ids}) GROUP BY m.ticker_id, day") as $r) {
                $mentions[(int) $r->ticker_id][(string) $r->day] = (int) $r->mentions;
            }
        }

        $trades = [];

        foreach ($events as $e) {
            $window = [];

            foreach ($bars[$e->ticker_id] ?? [] as $bar) {
                if ($bar['date'] < $e->entry_date) {
                    continue;
                }

                $window[] = $bar;

                if (count($window) >= ($config['max_hold'] ?? 5) + 2) {
                    break;
                }
            }

            if (count($window) < 3 || $window[0]['date'] !== $e->entry_date || $window[0]['open'] <= 0) {
                continue;
            }

            $seam = false;

            for ($i = 1; $i < count($window); $i++) {
                $gap = $window[$i]['open'] / max($window[$i - 1]['close'], 1e-9);

                if ($gap > 4.0 || $gap < 0.25) {
                    $seam = true;
                    break;
                }
            }

            if ($seam) {
                continue;
            }

            $offsets = [];

            if (isset($config['mention_collapse_frac'])) {
                $daily = $mentions[$e->ticker_id] ?? [];
                $offsets = [-1 => $daily[$e->day] ?? 0];
                $prev = $e->day;

                foreach ($window as $offset => $bar) {
                    $sum = 0;

                    for ($ts = strtotime($prev.' UTC') + 86400; $ts <= strtotime($bar['date'].' UTC'); $ts += 86400) {
                        $sum += $daily[gmdate('Y-m-d', $ts)] ?? 0;
                    }

                    $offsets[$offset] = $sum;
                    $prev = $bar['date'];
                }
            }

            $result = $simulator->simulate($config, $window[0]['open'], 0.0, null, $window, $offsets);

            if ($result['skipped'] || $result['return'] === null) {
                continue;
            }

            $friction = FrictionModel::roundTrip($window[0]['open'], $e->dollar_volume !== null ? (float) $e->dollar_volume : null);

            $trades[] = ['entry' => $e->entry_date, 'exit' => $result['date'], 'net' => $result['return'] - $friction];
        }

        usort($trades, fn ($a, $b) => strcmp($a['entry'], $b['entry']));

        return $trades;
    }

    /** @return array{0: string, 1: string, 2: int, 3: int, 4: string} */
    protected function slotSizing(array $trades, float $start, int $slots): array
    {
        return $this->walk($trades, $start, fn (float $equity, array $open): ?float => count($open) >= $slots
            ? null
            : min(($equity + array_sum(array_column($open, 'value'))) / $slots, $equity));
    }

    /** @return array{0: string, 1: string, 2: int, 3: int, 4: string} */
    protected function ticketSizing(array $trades, float $start, float $ticket): array
    {
        return $this->walk($trades, $start, function (float $equity, array $open) use ($ticket): ?float {
            $mark = $equity + array_sum(array_column($open, 'value'));
            $size = $mark * $ticket;

            return $size <= $equity ? $size : null; // cash-bounded
        });
    }

    /** @return array{0: string, 1: string, 2: int, 3: int, 4: string} */
    protected function walk(array $trades, float $start, \Closure $sizer): array
    {
        $equity = $start;
        $open = [];
        $taken = 0;
        $skipped = 0;
        $peak = $start;
        $maxDd = 0.0;

        foreach ($trades as $trade) {
            foreach ($open as $k => $position) {
                if ($position['exit'] <= $trade['entry']) {
                    $equity += $position['value'] * (1 + $position['net']);
                    unset($open[$k]);
                }
            }

            $size = $sizer($equity, $open);

            if ($size === null || $size <= 0) {
                $skipped++;

                continue;
            }

            $equity -= $size;
            $open[] = ['exit' => $trade['exit'], 'value' => $size, 'net' => $trade['net']];
            $taken++;

            $mark = $equity + array_sum(array_column($open, 'value'));
            $peak = max($peak, $mark);
            $maxDd = max($maxDd, 1 - $mark / $peak);
        }

        foreach ($open as $position) {
            $equity += $position['value'] * (1 + $position['net']);
        }

        return [
            '$'.number_format($equity),
            sprintf('%+.1f%%', ($equity / $start - 1) * 100),
            $taken,
            $skipped,
            sprintf('%.0f%%', $maxDd * 100),
        ];
    }
}
