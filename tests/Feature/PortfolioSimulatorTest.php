<?php

use App\Models\BacktestEvent;
use App\Models\BacktestRun;
use App\Models\Ticker;
use App\Services\Backtesting\PortfolioSimulator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedPortfolioRun(array $trades): BacktestRun
{
    $run = BacktestRun::create([
        'status' => 'done',
        'params' => ['from' => '2026-01-01', 'to' => '2026-06-01', 'friction' => 0.05],
        'results' => [],
    ]);

    $ticker = Ticker::create(['symbol' => 'PORT', 'name' => 'Portfolio Corp', 'is_active' => true]);

    foreach ($trades as $i => $trade) {
        $entry = CarbonImmutable::parse($trade['entry_date']);

        BacktestEvent::create([
            'backtest_run_id' => $run->id,
            'ticker_id' => $ticker->id,
            'symbol' => 'PORT',
            'day' => $entry->subDay()->toDateString(),
            'fired' => true,
            'composite' => 0.7,
            'zscore' => 3.0,
            'mentions' => 10,
            'unique_authors' => 8,
            'sentiment' => 0.2,
            'volume_z' => 3.0,
            'dollar_volume' => $trade['dollar_volume'] ?? 10_000_000,
            'pre_return_3d' => 0.05,
            'entry_date' => $entry->toDateString(),
            'entry' => 2.0,
            'return_1d' => 0.0,
            'return_3d' => 0.0,
            'return_5d' => $trade['exit_return'],
            'best_close_5d' => max($trade['exit_return'], 0.0),
            'exit_return' => $trade['exit_return'],
            'exit_reason' => 'time',
            'exit_day' => 5,
            'exit_date' => $entry->addDays(7)->toDateString(),
            'confidence' => $trade['confidence'],
            'hit' => $trade['exit_return'] >= 0.3,
            'classification' => 'prediction',
        ]);
    }

    return $run;
}

it('sizes up confident winners under Kelly and skips no-edge trades', function () {
    // 10 sequential non-overlapping trades: confident winners + hopeless losers.
    $trades = [];

    for ($i = 0; $i < 5; $i++) {
        $trades[] = ['entry_date' => '2026-0'.($i + 1).'-05', 'exit_return' => 0.50, 'confidence' => 0.55];
        $trades[] = ['entry_date' => '2026-0'.($i + 1).'-20', 'exit_return' => -0.10, 'confidence' => 0.02];
    }

    $result = app(PortfolioSimulator::class)->run(
        seedPortfolioRun($trades),
        ['kelly_min_history' => 4, 'kelly_min_bucket' => 2],
    );

    expect($result)->not->toHaveKey('error')
        ->and($result['trades'])->toBe(10)
        ->and($result['strategies'])->toHaveKeys(['equal', 'kelly_half', 'kelly_full']);

    $equal = $result['strategies']['equal'];
    $kelly = $result['strategies']['kelly_half'];

    // Equal weight trades everything. Once past warm-up, Kelly's as-of
    // history shows the low-confidence tercile only loses -> p=0 -> no bet,
    // while the confident tercile sizes up toward the 10% cap.
    expect($equal['trades_taken'])->toBe(10)
        ->and($kelly['trades_skipped'])->toBeGreaterThanOrEqual(3)
        ->and($kelly['avg_position_pct'])->toBeGreaterThan($equal['avg_position_pct'])
        ->and($kelly['final_equity'])->toBeGreaterThan($equal['final_equity']);

    // Equity curves aligned across strategies for charting.
    expect($result['curves'])->not->toBeEmpty()
        ->and($result['curves'][0])->toHaveKeys(['date', 'equal', 'kelly_half', 'kelly_full']);
});

it('caps position size at a fraction of signal-day dollar volume', function () {
    $trades = [
        // Confident winner but only $80k traded that day: 1% cap = $800,
        // far below the 10% equity cap ($10k on $100k initial equity).
        ['entry_date' => '2026-01-05', 'exit_return' => 0.50, 'confidence' => 0.60, 'dollar_volume' => 80_000],
        ['entry_date' => '2026-02-05', 'exit_return' => 0.50, 'confidence' => 0.60],
        ['entry_date' => '2026-03-05', 'exit_return' => 0.50, 'confidence' => 0.60],
        ['entry_date' => '2026-04-05', 'exit_return' => 0.50, 'confidence' => 0.60],
        ['entry_date' => '2026-05-05', 'exit_return' => 0.50, 'confidence' => 0.60],
    ];

    $result = app(PortfolioSimulator::class)->run(
        seedPortfolioRun($trades),
        ['kelly_min_history' => 4, 'kelly_min_bucket' => 2],
    );

    expect($result['strategies']['kelly_half']['liquidity_capped'])->toBeGreaterThanOrEqual(1);
});

it('reports an error when no scored trades exist', function () {
    $run = BacktestRun::create(['status' => 'done', 'params' => [], 'results' => []]);

    expect(app(PortfolioSimulator::class)->run($run))->toHaveKey('error');
});
