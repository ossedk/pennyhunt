<?php

use App\Models\MarketBar;
use App\Models\Signal;
use App\Models\Ticker;
use App\Models\TickerMetric;
use App\Services\MarketData\YahooMarketData;
use App\Services\Signals\SignalEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A candidate hot enough to clear the composite threshold on its own. */
function seedHotCandidate(string $symbol = 'GATE'): Ticker
{
    $ticker = Ticker::create(['symbol' => $symbol, 'name' => "{$symbol} Corp", 'is_active' => true]);

    TickerMetric::create([
        'ticker_id' => $ticker->id,
        'interval' => '1h',
        'bucket_start' => now()->startOfHour(),
        'mention_count' => 20,
        'unique_authors' => 15,
        'weighted_sentiment' => 0.8,
        'zscore_mentions' => 5.0,
    ]);

    return $ticker;
}

/** 31 daily bars ending today, at $close, flat volume except the latest. */
function seedGateBars(Ticker $ticker, float $close, float $latestVolumeMultiple): void
{
    $day = CarbonImmutable::now()->startOfDay();

    for ($i = 30; $i >= 0; $i--) {
        MarketBar::create([
            'ticker_id' => $ticker->id,
            'interval' => '1d',
            'bucket_start' => $day->subDays($i),
            'open' => $close,
            'high' => $close * 1.02,
            'low' => $close * 0.98,
            'close' => $close,
            'volume' => $i === 0 ? 100000 * $latestVolumeMultiple : 100000 + ($i % 5) * 5000,
        ]);
    }
}

beforeEach(function () {
    // No on-demand Yahoo calls in tests.
    $this->mock(YahooMarketData::class)
        ->shouldReceive('syncDailyBars')->andReturn(0)->byDefault();
});

it('fires when the market gate confirms (cheap + volume surge)', function () {
    $ticker = seedHotCandidate();
    seedGateBars($ticker, close: 2.50, latestVolumeMultiple: 8);

    $fired = app(SignalEngine::class)->run();

    expect($fired)->toHaveCount(1);

    $gate = Signal::first()->breakdown['market_gate'];
    expect($gate['passes'])->toBeTrue()
        ->and($gate['close'])->toEqual(2.5)
        ->and($gate['volume_z'])->toBeGreaterThan(2);
});

it('suppresses a signal above the price cap', function () {
    $ticker = seedHotCandidate();
    seedGateBars($ticker, close: 42.00, latestVolumeMultiple: 8);

    expect(app(SignalEngine::class)->run())->toHaveCount(0)
        ->and(Signal::count())->toBe(0);
});

it('suppresses a signal without volume confirmation', function () {
    $ticker = seedHotCandidate();
    seedGateBars($ticker, close: 2.50, latestVolumeMultiple: 1);

    expect(app(SignalEngine::class)->run())->toHaveCount(0);
});

it('suppresses a signal when no market data exists at all', function () {
    seedHotCandidate();

    expect(app(SignalEngine::class)->run())->toHaveCount(0);
});

it('stores confidence from the active model when a signal fires', function () {
    $ticker = seedHotCandidate();
    seedGateBars($ticker, close: 2.50, latestVolumeMultiple: 8);

    \App\Models\SignalModel::create([
        'version' => 'v-test-1',
        'train_from' => '2026-01-01',
        'train_to' => '2026-06-01',
        'train_events' => 500,
        'parameters' => [
            // Positive volume-z weight: the surge candidate must score > 50%.
            'weights' => ['zscore' => 0.0, 'volume_z' => 1.0, 'sentiment' => 0.0, 'breadth' => 0.0, 'pre_return_3d' => 0.0, 'log_dollar_volume' => 0.0],
            'bias' => 0.0,
            'means' => ['zscore' => 0.0, 'volume_z' => 0.0, 'sentiment' => 0.0, 'breadth' => 0.0, 'pre_return_3d' => 0.0, 'log_dollar_volume' => 0.0],
            'sds' => ['zscore' => 1.0, 'volume_z' => 1.0, 'sentiment' => 1.0, 'breadth' => 1.0, 'pre_return_3d' => 1.0, 'log_dollar_volume' => 1.0],
        ],
        'metrics' => [],
        'is_active' => true,
    ]);

    $fired = app(SignalEngine::class)->run();

    expect($fired)->toHaveCount(1);

    $signal = Signal::first();
    expect($signal->confidence)->toBeGreaterThan(0.5)
        ->and($signal->model_version)->toBe('v-test-1');
});

it('leaves confidence null when no model is active', function () {
    $ticker = seedHotCandidate();
    seedGateBars($ticker, close: 2.50, latestVolumeMultiple: 8);

    app(SignalEngine::class)->run();

    expect(Signal::first()->confidence)->toBeNull()
        ->and(Signal::first()->model_version)->toBeNull();
});

it('fires without gating when the gate is disabled', function () {
    config(['pennyhunt.signals.market_gate.enabled' => false]);
    seedHotCandidate();

    $fired = app(SignalEngine::class)->run();

    expect($fired)->toHaveCount(1)
        ->and(Signal::first()->breakdown['market_gate'])->toBeNull();
});
