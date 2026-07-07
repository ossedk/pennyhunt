<?php

namespace App\Http\Controllers;

use App\Jobs\Backtesting\RunBacktest;
use App\Models\BacktestEvent;
use App\Models\BacktestRun;
use App\Models\MarketBar;
use App\Models\PostTickerMention;
use App\Models\SignalModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BacktestsController extends Controller
{
    public function index(Request $request): Response
    {
        $runs = BacktestRun::query()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (BacktestRun $run): array => [
                'id' => $run->id,
                'name' => $run->name,
                'status' => $run->status,
                'params' => $run->params,
                'results' => $run->results,
                'error' => $run->error,
                'created_at' => $run->created_at->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ]);

        $selectedId = (int) ($request->query('run') ?: $runs->firstWhere('status', 'done')['id'] ?? 0);

        $events = $selectedId
            ? BacktestEvent::query()
                ->where('backtest_run_id', $selectedId)
                ->where('fired', true)
                ->orderByDesc('day')
                ->orderBy('symbol')
                ->paginate(50)
                ->withQueryString()
            : null;

        return Inertia::render('backtests', [
            'runs' => $runs,
            'selectedRunId' => $selectedId ?: null,
            'events' => $events,
            'dataCoverage' => [
                'first_mention' => PostTickerMention::min('posted_at'),
                'last_mention' => PostTickerMention::max('posted_at'),
                'mention_count' => PostTickerMention::count(),
                'tickers_with_bars' => MarketBar::distinct('ticker_id')->count('ticker_id'),
            ],
            // Model registry: where the nightly shadow retrains land. Brier
            // trend shows whether new features/data actually improve skill.
            'models' => SignalModel::query()
                ->orderByDesc('id')
                ->limit(15)
                ->get()
                ->map(fn (SignalModel $m): array => [
                    'id' => $m->id,
                    'version' => $m->version,
                    'role' => $m->role,
                    'backtest_run_id' => $m->backtest_run_id,
                    'is_active' => $m->is_active,
                    'train_events' => $m->train_events,
                    'created_at' => $m->created_at->toIso8601String(),
                    'brier' => $m->metrics['brier'] ?? null,
                    'brier_reference' => $m->metrics['brier_reference'] ?? null,
                    'base_rate' => $m->metrics['base_rate'] ?? null,
                    'oos_events' => $m->metrics['oos_events'] ?? null,
                    'trade_tier' => $m->metrics['trade_tier'] ?? null,
                    'auc' => $m->metrics['auc'] ?? null,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'threshold' => ['required', 'numeric', 'between:0,1'],
            'min_daily_mentions' => ['required', 'integer', 'between:1,100'],
            'hit_threshold' => ['required', 'numeric', 'between:0.05,2'],
            'friction' => ['nullable', 'numeric', 'between:0,0.5'],
            'min_volume_z' => ['nullable', 'numeric', 'between:0,10'],
            'max_pre_run' => ['nullable', 'numeric', 'between:0,2'],
            'max_entry_price' => ['nullable', 'numeric', 'between:0.01,10000'],
            'stop_loss' => ['nullable', 'numeric', 'between:0.01,0.9'],
            'take_profit' => ['nullable', 'numeric', 'between:0.01,5'],
        ]);

        $run = BacktestRun::create([
            'status' => 'pending',
            'params' => [
                ...array_filter($validated, fn ($v) => $v !== null),
                'friction' => (float) ($validated['friction'] ?? 0.05),
                'baseline_days' => (int) config('pennyhunt.signals.baseline_days'),
                'cooldown_days' => 3,
            ],
        ]);

        RunBacktest::dispatch($run->id);

        return redirect()->route('backtests', ['run' => $run->id]);
    }
}
