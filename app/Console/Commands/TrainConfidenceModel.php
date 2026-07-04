<?php

namespace App\Console\Commands;

use App\Models\BacktestRun;
use App\Models\SignalModel;
use App\Services\Ml\ConfidenceTrainer;
use Illuminate\Console\Command;

/**
 * The confidence pipeline in one shot:
 *
 *  1. Walk-forward score every backtest_event of the run (monthly refits, no
 *     look-ahead) — writes events.confidence, the Kelly simulator's input.
 *  2. Train the final model on the full run and activate it for live signal
 *     scoring at fire time.
 *
 * Prints Brier/calibration so miscalibration is visible before anyone sizes
 * positions off these probabilities.
 */
class TrainConfidenceModel extends Command
{
    protected $signature = 'pennyhunt:train-confidence {--run= : Backtest run id (default: latest done run)}';

    protected $description = 'Walk-forward confidence scoring + train/activate the live confidence model';

    public function handle(ConfidenceTrainer $trainer): int
    {
        $run = $this->option('run')
            ? BacktestRun::findOrFail((int) $this->option('run'))
            : BacktestRun::query()->where('status', 'done')->orderByDesc('finished_at')->firstOrFail();

        $this->info("Walk-forward scoring run #{$run->id} ({$run->params['from']} → {$run->params['to']})...");

        $walkForward = $trainer->walkForwardScore($run);

        $this->line("Scored {$walkForward['events_scored']} of {$walkForward['events_total']} events (earliest months are warm-up).");
        $this->printQuality($walkForward);

        $run->forceFill(['results' => [...$run->results, 'confidence' => $walkForward]])->save();

        $this->newLine();
        $this->info('Training final model on the full run...');

        $model = $trainer->train($run);

        if (is_array($model)) {
            $this->error($model['error']);

            return self::FAILURE;
        }

        $this->line("Model {$model->version} activated ({$model->train_events} events, {$model->train_from->toDateString()} → {$model->train_to->toDateString()}).");
        $this->printQuality($model->metrics);
        $this->table(
            ['feature', 'weight (standardized)'],
            collect($model->parameters['weights'])->map(fn ($w, $f) => [$f, round($w, 4)])->values()->all(),
        );

        return self::SUCCESS;
    }

    /** @param array<string, mixed>|SignalModel $metrics */
    protected function printQuality(array $metrics): void
    {
        $brier = $metrics['brier'] ?? null;
        $ref = $metrics['brier_reference'] ?? null;
        $base = $metrics['base_rate'] ?? null;

        $this->line("Brier: {$brier} (always-base-rate reference: {$ref}, base rate: {$base})");

        foreach ($metrics['reliability'] ?? [] as $i => $bucket) {
            $this->line(sprintf(
                '  bucket %d: predicted %.3f vs realized %.3f (n=%d)',
                $i + 1,
                $bucket['predicted'],
                $bucket['realized'],
                $bucket['count'],
            ));
        }
    }
}
