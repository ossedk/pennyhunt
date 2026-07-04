<?php

use App\Models\BacktestEvent;
use App\Models\BacktestRun;
use App\Models\SignalModel;
use App\Models\Ticker;
use App\Services\Ml\ConfidenceTrainer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Seeds a run whose events have a perfectly learnable pattern: high volume-z
 * candidates hit, low volume-z candidates don't. 100 events/month.
 */
function seedScorableRun(int $months = 5): BacktestRun
{
    $run = BacktestRun::create([
        'status' => 'done',
        'params' => ['from' => '2026-01-01', 'to' => '2026-06-01', 'friction' => 0.05],
        'results' => [],
    ]);

    $ticker = Ticker::create(['symbol' => 'CONF', 'name' => 'Confidence Corp', 'is_active' => true]);

    $start = CarbonImmutable::parse('2026-01-05');
    $rows = [];

    for ($m = 0; $m < $months; $m++) {
        for ($i = 0; $i < 100; $i++) {
            $winner = $i % 4 === 0; // 25% base rate
            $day = $start->addMonths($m)->addDays($i % 25);

            $rows[] = [
                'backtest_run_id' => $run->id,
                'ticker_id' => $ticker->id,
                'symbol' => 'CONF',
                'day' => $day->toDateString(),
                'fired' => true,
                'composite' => 0.7,
                'zscore' => 3.0,
                'mentions' => 10,
                'unique_authors' => 8,
                'sentiment' => 0.2,
                'volume_z' => $winner ? 5.0 : 0.5,
                'dollar_volume' => 2_000_000,
                'pre_return_3d' => 0.05,
                'entry_date' => $day->addDay()->toDateString(),
                'entry' => 2.0,
                'return_1d' => 0.0,
                'return_3d' => 0.0,
                'return_5d' => $winner ? 0.5 : -0.05,
                'best_close_5d' => $winner ? 0.5 : 0.0,
                'hit' => $winner,
                'classification' => 'prediction',
                'created_at' => now(),
            ];
        }
    }

    BacktestEvent::insert($rows);

    return $run;
}

it('walk-forward scores events without look-ahead and beats the base-rate Brier', function () {
    $run = seedScorableRun();

    $result = app(ConfidenceTrainer::class)->walkForwardScore($run);

    // 3 warm-up months (300 events history required), months 4-5 scored.
    expect($result['events_total'])->toBe(500)
        ->and($result['events_scored'])->toBe(200)
        ->and($result['brier'])->toBeLessThan($result['brier_reference']);

    // Warm-up months untouched, scored months persisted.
    expect(BacktestEvent::whereNotNull('confidence')->count())->toBe(200);

    // The learnable pattern must show: winners scored higher than losers.
    $scored = BacktestEvent::whereNotNull('confidence')->get();
    $avgWinner = $scored->where('hit', true)->avg('confidence');
    $avgLoser = $scored->where('hit', false)->avg('confidence');

    expect($avgWinner)->toBeGreaterThan($avgLoser + 0.2);
});

it('trains, persists and activates the live model', function () {
    $run = seedScorableRun();

    $model = app(ConfidenceTrainer::class)->train($run);

    expect($model)->toBeInstanceOf(SignalModel::class)
        ->and($model->is_active)->toBeTrue()
        ->and($model->train_events)->toBe(500)
        ->and($model->metrics['brier'])->toBeLessThan($model->metrics['brier_reference']);

    // Prediction API discriminates winner-like from loser-like features.
    $base = [
        'zscore' => 3.0, 'sentiment' => 0.2, 'unique_authors' => 8, 'mentions' => 10,
        'pre_return_3d' => 0.05, 'dollar_volume' => 2_000_000,
    ];
    $winnerP = $model->predict(ConfidenceTrainer::features([...$base, 'volume_z' => 5.0]));
    $loserP = $model->predict(ConfidenceTrainer::features([...$base, 'volume_z' => 0.5]));

    expect($winnerP)->toBeGreaterThan(0.5)
        ->and($loserP)->toBeLessThan(0.1);

    // Retraining deactivates the previous model.
    $second = app(ConfidenceTrainer::class)->train($run);

    expect($second->is_active)->toBeTrue()
        ->and($model->refresh()->is_active)->toBeFalse()
        ->and(SignalModel::active()->id)->toBe($second->id);
});

it('refuses to train on too few events', function () {
    $run = BacktestRun::create(['status' => 'done', 'params' => [], 'results' => []]);

    $result = app(ConfidenceTrainer::class)->train($run);

    expect($result)->toBeArray()->toHaveKey('error');
});
