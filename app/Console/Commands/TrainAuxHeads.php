<?php

namespace App\Console\Commands;

use App\Models\BacktestEvent;
use App\Models\BacktestRun;
use App\Services\Backtesting\ExitSimulator;
use App\Services\Backtesting\FrictionModel;
use App\Services\Ml\ConfidenceTrainer;
use App\Support\AnalyticsGate;
use App\Support\Memory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Phase F auxiliary heads (walk-forward, no look-ahead):
 *  - moonshot head: P(best_close_5d >= +75%) — the fat tail specifically.
 *  - meta head: P(a phase-E-discipline trade of this event nets > 0) —
 *    meta-labeling: predicting the TRADE outcome under the real exit
 *    rules + per-ticker friction, not the raw price label.
 *
 * Scores land on backtest_events (moonshot_confidence / meta_confidence)
 * for exit-lab slicing and, later, live gating.
 */
class TrainAuxHeads extends Command
{
    protected $signature = 'pennyhunt:train-aux-heads
        {--run= : Backtest run id (default: latest done run)}';

    protected $description = 'Train walk-forward moonshot + meta heads and store per-event scores';

    public function handle(ExitSimulator $simulator): int
    {
        Memory::raise('3072M');

        $run = $this->option('run')
            ? BacktestRun::findOrFail((int) $this->option('run'))
            : BacktestRun::query()->where('status', 'done')->orderByDesc('finished_at')->firstOrFail();

        $python = config('pennyhunt.ml.python');

        if (! is_executable($python)) {
            $this->error("Python interpreter not found at {$python}.");

            return self::FAILURE;
        }

        $csv = storage_path("app/aux_events_run{$run->id}.csv");
        $outPrefix = storage_path("app/aux_run{$run->id}");

        $this->info("Computing phase-E outcomes + exporting run #{$run->id} events...");
        $count = $this->exportEvents($run, $simulator, $csv);
        $this->line("  {$count} events → {$csv}");

        $this->info('Training moonshot + meta heads (walk-forward)...');
        $result = Process::timeout(1800)->run([$python, base_path('scripts/train_aux_heads.py'), $csv, $outPrefix]);

        if ($result->failed()) {
            $this->error('Training failed: '.$result->errorOutput());

            return self::FAILURE;
        }

        $this->line(trim($result->output()));

        $auxCsv = "{$outPrefix}_aux.csv";

        if (! is_file($auxCsv)) {
            $this->error('No aux score file produced.');

            return self::FAILURE;
        }

        $fh = fopen($auxCsv, 'r');
        fgetcsv($fh, escape: '\\'); // header
        $updates = 0;

        while (($row = fgetcsv($fh, escape: '\\')) !== false) {
            DB::table('backtest_events')->where('id', (int) $row[0])->update([
                'moonshot_confidence' => round((float) $row[1], 6),
                'meta_confidence' => $row[2] !== '' ? round((float) $row[2], 6) : null,
            ]);
            $updates++;
        }

        fclose($fh);
        $this->info("{$updates} events updated with aux head scores.");

        return self::SUCCESS;
    }

    /** Exports features + labels; meta label = phase-E trade net > 0. */
    protected function exportEvents(BacktestRun $run, ExitSimulator $simulator, string $path): int
    {
        // Bars for every event ticker (phase-E outcome simulation).
        $tickerIds = BacktestEvent::query()->where('backtest_run_id', $run->id)
            ->distinct()->pluck('ticker_id');

        $bars = [];

        DB::table('market_bars')
            ->whereIn('ticker_id', $tickerIds)
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

        $gate = AnalyticsGate::mentionJoin('m');
        $ids = $tickerIds->implode(',');
        $mentions = [];

        foreach (DB::select("SELECT m.ticker_id, date(m.posted_at) AS day, COUNT(*) AS mentions FROM post_ticker_mentions m {$gate} WHERE m.ticker_id IN ({$ids}) GROUP BY m.ticker_id, day") as $r) {
            $mentions[(int) $r->ticker_id][(string) $r->day] = (int) $r->mentions;
        }

        $config = ['mention_collapse_frac' => 0.25, 'max_hold' => 10];

        $fh = fopen($path, 'w');
        $features = ConfidenceTrainer::FEATURES;
        fputcsv($fh, ['id', 'day', 'label_moonshot', 'label_meta', ...$features], escape: '\\');
        $count = 0;

        BacktestEvent::query()
            ->where('backtest_run_id', $run->id)
            ->orderBy('day')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use ($fh, $features, $simulator, $config, $bars, $mentions, &$count): void {
                foreach ($rows as $e) {
                    $f = ConfidenceTrainer::features($e->only(ConfidenceTrainer::RAW_INPUT_COLUMNS));

                    fputcsv($fh, [
                        $e->id,
                        $e->day->toDateString(),
                        $e->best_close_5d !== null && (float) $e->best_close_5d >= 0.75 ? 1 : 0,
                        $this->metaLabel($e, $simulator, $config, $bars, $mentions),
                        ...array_map(fn (string $k) => $f[$k], $features),
                    ], escape: '\\');

                    $count++;
                }
            });

        fclose($fh);

        return $count;
    }

    /** 1/0 when the phase-E trade of this event nets >/<= 0; '' when unpriceable. */
    protected function metaLabel(BacktestEvent $e, ExitSimulator $simulator, array $config, array $bars, array $mentions): string
    {
        $entryDate = $e->entry_date?->toDateString();

        if ($entryDate === null) {
            return '';
        }

        $window = [];

        foreach ($bars[$e->ticker_id] ?? [] as $bar) {
            if ($bar['date'] < $entryDate) {
                continue;
            }

            $window[] = $bar;

            if (count($window) >= 11) {
                break;
            }
        }

        if (count($window) < 3 || $window[0]['date'] !== $entryDate || $window[0]['open'] <= 0) {
            return '';
        }

        for ($i = 1; $i < count($window); $i++) {
            if ($window[$i - 1]['close'] <= 0) {
                return '';
            }

            $gap = $window[$i]['open'] / $window[$i - 1]['close'];

            if ($gap > 4.0 || $gap < 0.25) {
                return ''; // split seam
            }
        }

        // Mention offsets for the collapse exit.
        $daily = $mentions[$e->ticker_id] ?? [];
        $fireDay = $e->day->toDateString();
        $offsets = [-1 => $daily[$fireDay] ?? 0];
        $prev = $fireDay;

        foreach ($window as $offset => $bar) {
            $sum = 0;

            for ($ts = strtotime($prev.' UTC') + 86400; $ts <= strtotime($bar['date'].' UTC'); $ts += 86400) {
                $sum += $daily[gmdate('Y-m-d', $ts)] ?? 0;
            }

            $offsets[$offset] = $sum;
            $prev = $bar['date'];
        }

        $result = $simulator->simulate($config, $window[0]['open'], 0.0, null, $window, $offsets);

        if ($result['skipped'] || $result['return'] === null) {
            return '';
        }

        $friction = FrictionModel::roundTrip($window[0]['open'], $e->dollar_volume !== null ? (float) $e->dollar_volume : null);

        return $result['return'] - $friction > 0 ? '1' : '0';
    }
}
