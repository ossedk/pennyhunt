<?php

namespace App\Jobs\Backtesting;

use App\Models\BacktestRun;
use App\Services\Backtesting\Backtester;
use App\Services\Backtesting\PortfolioSimulator;
use App\Services\Ml\ConfidenceTrainer;
use App\Support\Memory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunBacktest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $backtestRunId)
    {
        $this->onQueue('metrics');
    }

    public function handle(Backtester $backtester): void
    {
        // A 12-month multi-thousand-ticker replay peaks near 300MB; the
        // 24-month window roughly doubles that. 3GB gives comfortable
        // headroom either way.
        Memory::raise('3072M');

        $run = BacktestRun::findOrFail($this->backtestRunId);

        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();

        try {
            $backtester->run($run);
            $this->scoreAndSimulate($run->refresh());
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            throw $e;
        }
    }

    /**
     * Post-processing: walk-forward confidence over the run's events (no
     * look-ahead) and the Kelly portfolio comparison. Both are per-run
     * research artifacts — the ACTIVE live model is only retrained via the
     * explicit pennyhunt:train-confidence command. Best-effort: a run with
     * too few events simply skips these panels.
     */
    protected function scoreAndSimulate(BacktestRun $run): void
    {
        try {
            $confidence = app(ConfidenceTrainer::class)->walkForwardScore($run);
            $results = [...$run->results, 'confidence' => $confidence];

            if ($confidence['events_scored'] > 0) {
                $portfolio = app(PortfolioSimulator::class)->run($run);

                if (! isset($portfolio['error'])) {
                    $results['portfolio'] = $portfolio;
                }
            }

            $run->forceFill(['results' => $results])->save();
        } catch (Throwable $e) {
            report($e); // never fail a finished backtest over the extras
        }
    }
}
