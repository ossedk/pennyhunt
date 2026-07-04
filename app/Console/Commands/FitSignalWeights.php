<?php

namespace App\Console\Commands;

use App\Models\BacktestRun;
use App\Services\Backtesting\WeightFitter;
use Illuminate\Console\Command;

/**
 * Fits P(hit | features) on a backtest run's full candidate set and reports
 * out-of-sample precision@k vs base rate. Results are stored on the run
 * (results.weight_fit) so the /backtests page and audits can reference them.
 */
class FitSignalWeights extends Command
{
    protected $signature = 'pennyhunt:fit-weights {--run= : Backtest run id (default: latest done run)}';

    protected $description = 'Walk-forward logistic regression over backtest events';

    public function handle(WeightFitter $fitter): int
    {
        $run = $this->option('run')
            ? BacktestRun::findOrFail((int) $this->option('run'))
            : BacktestRun::query()->where('status', 'done')->orderByDesc('finished_at')->firstOrFail();

        $this->info("Fitting on run #{$run->id} ({$run->params['from']} → {$run->params['to']})");

        $fit = $fitter->fit($run);

        if (isset($fit['error'])) {
            $this->error($fit['error']);

            return self::FAILURE;
        }

        $run->forceFill(['results' => [...$run->results, 'weight_fit' => $fit]])->save();

        $this->table(['feature', 'weight (standardized)'], collect($fit['weights'])
            ->map(fn ($w, $f) => [$f, $w])
            ->values()
            ->all());

        $this->line("Split day: {$fit['split_day']} (train {$fit['train_events']} / test {$fit['test_events']})");
        $this->line("Test base rate: {$fit['base_rate_test']}");

        foreach ($fit['precision_at_k'] as $row) {
            $this->line("precision@{$row['k']}: {$row['precision']} (lift x{$row['lift']})");
        }

        return self::SUCCESS;
    }
}
