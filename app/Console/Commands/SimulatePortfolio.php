<?php

namespace App\Console\Commands;

use App\Models\BacktestRun;
use App\Services\Backtesting\PortfolioSimulator;
use Illuminate\Console\Command;

/**
 * Portfolio-level replay of a backtest run's fired events comparing
 * equal-weight vs half/full Kelly sizing from walk-forward confidence.
 * Results are stored on the run (results.portfolio) for the /backtests page.
 */
class SimulatePortfolio extends Command
{
    protected $signature = 'pennyhunt:simulate-portfolio {--run= : Backtest run id (default: latest done run)}';

    protected $description = 'Equity-curve simulation: equal-weight vs Kelly sizing from confidence';

    public function handle(PortfolioSimulator $simulator): int
    {
        $run = $this->option('run')
            ? BacktestRun::findOrFail((int) $this->option('run'))
            : BacktestRun::query()->where('status', 'done')->orderByDesc('finished_at')->firstOrFail();

        $this->info("Simulating portfolio for run #{$run->id} ({$run->params['from']} → {$run->params['to']})");

        $result = $simulator->run($run);

        if (isset($result['error'])) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $run->forceFill(['results' => [...$run->results, 'portfolio' => $result]])->save();

        $rows = collect($result['strategies'])->map(fn ($s, $name) => [
            $name,
            number_format($s['final_equity'], 0),
            round($s['total_return'] * 100, 1).'%',
            round($s['max_drawdown'] * 100, 1).'%',
            $s['trades_taken'],
            $s['trades_skipped'],
            $s['liquidity_capped'],
            $s['avg_position_pct'] !== null ? round($s['avg_position_pct'] * 100, 1).'%' : '—',
        ])->values()->all();

        $this->table(
            ['strategy', 'final equity', 'return', 'max DD', 'taken', 'skipped', 'liq-capped', 'avg pos'],
            $rows,
        );

        return self::SUCCESS;
    }
}
