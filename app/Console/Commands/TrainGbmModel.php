<?php

namespace App\Console\Commands;

use App\Models\BacktestEvent;
use App\Models\BacktestRun;
use App\Models\SignalModel;
use App\Services\Ml\ConfidenceTrainer;
use App\Support\Memory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Phase C model pipeline: export a run's backtest events, train the gradient
 * boosting + isotonic-calibration model in Python (scripts/train_gbm_model.py,
 * walk-forward metrics, no look-ahead), then import the JSON artifact as a
 * SignalModel. Prediction at fire time is pure PHP (SignalModel::predictGbm) —
 * Python is only needed here, at training time.
 *
 * Parity vectors shipped inside the artifact are re-scored through the PHP
 * evaluator before anything is persisted: if PHP and sklearn disagree, the
 * import aborts rather than shipping a silently-wrong model.
 */
class TrainGbmModel extends Command
{
    protected $signature = 'pennyhunt:train-gbm
        {--run= : Backtest run id (default: latest done run)}
        {--activate : Activate the imported model for live scoring}';

    protected $description = 'Train the GBM confidence model (Python) and import it for live PHP scoring';

    public function handle(): int
    {
        Memory::raise('1024M'); // 32k-event export + artifact import

        $run = $this->option('run')
            ? BacktestRun::findOrFail((int) $this->option('run'))
            : BacktestRun::query()->where('status', 'done')->orderByDesc('finished_at')->firstOrFail();

        $python = config('pennyhunt.ml.python');

        if (! is_executable($python)) {
            $this->error("Python interpreter not found at {$python} (set PENNYHUNT_ML_PYTHON).");

            return self::FAILURE;
        }

        $csv = storage_path("app/gbm_events_run{$run->id}.csv");
        $artifactPath = storage_path("app/gbm_model_run{$run->id}.json");

        $this->info("Exporting run #{$run->id} events...");
        $count = $this->exportEvents($run, $csv);
        $this->line("  {$count} events → {$csv}");

        $this->info('Training GBM + isotonic calibration (walk-forward metrics)...');
        $result = Process::timeout(1800)->run([
            $python, base_path('scripts/train_gbm_model.py'), $csv, $artifactPath,
        ]);

        if ($result->failed()) {
            $this->error('Training failed: '.$result->errorOutput());

            return self::FAILURE;
        }

        $artifact = json_decode((string) file_get_contents($artifactPath), true);

        if (! is_array($artifact) || ($artifact['type'] ?? null) !== 'gbm') {
            $this->error('Training produced no valid artifact.');

            return self::FAILURE;
        }

        // Store per-event walk-forward (out-of-sample) GBM scores — the
        // honest tier signal for exit-lab slicing and Kelly payoff ratios.
        $oosCsv = str_replace('.json', '_oos.csv', $artifactPath);

        if (is_file($oosCsv)) {
            $fh = fopen($oosCsv, 'r');
            fgetcsv($fh, escape: '\\'); // header
            $updates = 0;

            while (($row = fgetcsv($fh, escape: '\\')) !== false) {
                DB::table('backtest_events')->where('id', (int) $row[0])->update(['gbm_confidence' => round((float) $row[1], 6)]);
                $updates++;
            }

            fclose($fh);
            $this->line("  {$updates} events updated with walk-forward GBM confidence.");
        }

        $model = new SignalModel(['parameters' => $artifact]);

        foreach ($artifact['parity'] as $i => $vector) {
            $got = $model->predict($vector['features']);

            if (abs($got - $vector['calibrated_p']) > 0.001) {
                $this->error(sprintf(
                    'Parity check %d FAILED: PHP %.4f vs sklearn %.4f — artifact not imported.',
                    $i,
                    $got,
                    $vector['calibrated_p'],
                ));

                return self::FAILURE;
            }
        }

        $this->line('  Parity: PHP evaluator matches sklearn on all '.count($artifact['parity']).' vectors.');

        $metrics = $artifact['metrics'];

        DB::transaction(function () use ($run, $artifact, $metrics, &$model): void {
            if ($this->option('activate')) {
                SignalModel::query()->update(['is_active' => false]);
            }

            $model = SignalModel::create([
                'version' => 'gbm-v'.now()->format('Y-m-d').'-run'.$run->id.'.'.(SignalModel::count() + 1),
                'backtest_run_id' => $run->id,
                'train_from' => $artifact['train_from'],
                'train_to' => $artifact['train_to'],
                'train_events' => $artifact['train_events'],
                'parameters' => $artifact,
                'metrics' => [
                    'oos_events' => $metrics['oos_events'],
                    'brier' => $metrics['brier_calibrated'],
                    'brier_raw' => $metrics['brier_raw'],
                    'brier_reference' => $metrics['brier_reference'],
                    'base_rate' => $metrics['base_rate'],
                    'reliability' => $metrics['reliability'],
                    'trade_tier' => $metrics['trade_tier'],
                ],
                'is_active' => $this->option('activate'),
            ]);
        });

        $this->newLine();
        $this->info("Model {$model->version} imported".($model->is_active ? ' and ACTIVATED' : ' (inactive — pass --activate to ship it)').'.');
        $this->line(sprintf(
            'Walk-forward Brier %.5f (raw %.5f, base-rate reference %.5f) over %d out-of-sample events.',
            $metrics['brier_calibrated'],
            $metrics['brier_raw'],
            $metrics['brier_reference'],
            $metrics['oos_events'],
        ));
        $this->line(sprintf(
            'Research trade tier raw p ≥ %.2f ↔ calibrated p ≥ %.4f.',
            $metrics['trade_tier']['raw_p'],
            $metrics['trade_tier']['calibrated_p'],
        ));

        foreach ($metrics['reliability'] as $i => $bucket) {
            $this->line(sprintf(
                '  decile %2d: predicted %.4f vs realized %.4f (n=%d)',
                $i + 1,
                $bucket['predicted'],
                $bucket['realized'],
                $bucket['count'],
            ));
        }

        return self::SUCCESS;
    }

    /** Export the run's events with the exact trainer feature definitions. */
    protected function exportEvents(BacktestRun $run, string $path): int
    {
        $fh = fopen($path, 'w');
        $features = ConfidenceTrainer::FEATURES;
        fputcsv($fh, ['id', 'day', 'fired', 'hit', 'exit_return', ...$features], escape: '\\');
        $count = 0;

        BacktestEvent::query()
            ->where('backtest_run_id', $run->id)
            ->orderBy('day')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use ($fh, $features, &$count): void {
                foreach ($rows as $e) {
                    $f = ConfidenceTrainer::features($e->only(ConfidenceTrainer::RAW_INPUT_COLUMNS));

                    fputcsv($fh, [
                        $e->id, $e->day->toDateString(), $e->fired ? 1 : 0, $e->hit ? 1 : 0, $e->exit_return,
                        ...array_map(fn (string $k) => $f[$k], $features),
                    ], escape: '\\');

                    $count++;
                }
            });

        fclose($fh);

        return $count;
    }
}
