<?php

use App\Models\MarketBar;
use App\Models\Signal;
use App\Models\SignalModel;
use App\Models\SignalTrade;
use App\Models\Ticker;
use App\Services\Trading\TradeEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function tradeModel(): SignalModel
{
    return SignalModel::create([
        'version' => 'gbm-test.1',
        'train_from' => '2026-01-01',
        'train_to' => '2026-06-01',
        'train_events' => 1000,
        'parameters' => ['type' => 'gbm', 'features' => [], 'baseline' => 0.0, 'trees' => [], 'isotonic' => ['x' => [0, 1], 'y' => [0, 1]]],
        'metrics' => ['trade_tier' => ['raw_p' => 0.15, 'calibrated_p' => 0.124]],
        'is_active' => true,
    ]);
}

function tradeSignal(float $confidence, string $firedAt = '2026-06-01 15:00:00'): Signal
{
    $ticker = Ticker::create(['symbol' => 'TRD'.random_int(10, 99), 'name' => 'Trade Test', 'is_active' => true]);

    return Signal::create([
        'ticker_id' => $ticker->id,
        'fired_at' => $firedAt,
        'composite_score' => 0.8,
        'confidence' => $confidence,
        'model_version' => 'gbm-test.1',
        'breakdown' => [],
        'state' => 'new',
    ]);
}

function tradeBar(int $tickerId, string $date, float $open, float $high, float $low, float $close): void
{
    MarketBar::create([
        'ticker_id' => $tickerId, 'interval' => '1d', 'bucket_start' => $date.' 00:00:00',
        'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => 1_000_000,
    ]);
}

it('opens pending trades only at or above the trade tier', function () {
    tradeModel();
    $engine = app(TradeEngine::class);

    // One trade per book (legacy + phase_e) at/above tier; none below.
    expect($engine->createForSignal(tradeSignal(0.20)))->not->toBeNull()
        ->and($engine->createForSignal(tradeSignal(0.05)))->toBeNull()
        ->and(SignalTrade::count())->toBe(2)
        ->and(SignalTrade::query()->pluck('book')->sort()->values()->all())->toBe(['legacy', 'phase_e'])
        ->and(SignalTrade::first()->status)->toBe('pending_entry');
});

it('phase-e book vetoes gapped entries and survives intraday wicks the legacy book stops on', function () {
    tradeModel();
    $signal = tradeSignal(0.20, '2026-06-01 15:00:00');
    app(TradeEngine::class)->createForSignal($signal);

    // Entry gaps +100% over the signal close: phase_e vetoes, legacy enters.
    tradeBar($signal->ticker_id, '2026-06-01', 1.00, 1.10, 0.95, 1.00);
    tradeBar($signal->ticker_id, '2026-06-02', 2.00, 2.20, 1.90, 2.10);

    app(TradeEngine::class)->sync();

    expect(SignalTrade::where('book', 'phase_e')->first()->status)->toBe('cancelled')
        ->and(SignalTrade::where('book', 'phase_e')->first()->exit_reason)->toBe('gap_veto')
        ->and(SignalTrade::where('book', 'legacy')->first()->status)->toBe('open');
});

it('phase-e stop only triggers on a close through the level, not a wick', function () {
    tradeModel();
    $signal = tradeSignal(0.20, '2026-06-01 15:00:00');
    app(TradeEngine::class)->createForSignal($signal);

    tradeBar($signal->ticker_id, '2026-06-01', 1.00, 1.05, 0.95, 1.00); // signal day (no gap)
    tradeBar($signal->ticker_id, '2026-06-02', 1.02, 1.06, 0.98, 1.04); // entry day
    // Deep intraday wick to 0.70 but closes at 1.00: legacy (stop 0.918)
    // stops out on the wick; phase_e (close-based) holds.
    tradeBar($signal->ticker_id, '2026-06-03', 1.03, 1.05, 0.70, 1.00);

    app(TradeEngine::class)->sync();

    expect(SignalTrade::where('book', 'legacy')->first()->status)->toBe('closed')
        ->and(SignalTrade::where('book', 'legacy')->first()->exit_reason)->toBe('stop')
        ->and(SignalTrade::where('book', 'phase_e')->first()->status)->toBe('open');
});

it('does not open trades without an active tiered model', function () {
    expect(app(TradeEngine::class)->createForSignal(tradeSignal(0.99)))->toBeNull();
});

it('fills the entry at the next session open and sets the 10% stop', function () {
    tradeModel();
    $signal = tradeSignal(0.20, '2026-06-01 15:00:00');
    $trade = app(TradeEngine::class)->createForSignal($signal);

    tradeBar($signal->ticker_id, '2026-06-01', 1.00, 1.10, 0.95, 1.05); // signal day
    tradeBar($signal->ticker_id, '2026-06-02', 2.00, 2.20, 1.90, 2.10); // entry day

    app(TradeEngine::class)->sync();
    $trade->refresh();

    expect($trade->status)->toBe('open')
        ->and($trade->entry_date->toDateString())->toBe('2026-06-02')
        ->and($trade->entry_price)->toEqual(2.0)
        ->and($trade->stop_price)->toEqual(1.8);
});

it('fills a gap through the stop at the open, worse than the stop', function () {
    tradeModel();
    $signal = tradeSignal(0.20);
    app(TradeEngine::class)->createForSignal($signal);

    tradeBar($signal->ticker_id, '2026-06-02', 2.00, 2.20, 1.90, 2.10); // entry day, no breach
    tradeBar($signal->ticker_id, '2026-06-03', 1.50, 1.60, 1.40, 1.55); // gaps through 1.80 stop

    app(TradeEngine::class)->sync();
    $trade = SignalTrade::first();

    expect($trade->status)->toBe('closed')
        ->and($trade->exit_reason)->toBe('stop')
        ->and($trade->exit_price)->toEqual(1.5)
        ->and($trade->exit_return)->toEqual(-0.25)
        ->and($trade->net_return)->toEqual(-0.30);
});

it('fills an intraday stop breach at the stop price, including entry day', function () {
    tradeModel();
    $signal = tradeSignal(0.20);
    app(TradeEngine::class)->createForSignal($signal);

    tradeBar($signal->ticker_id, '2026-06-02', 2.00, 2.20, 1.75, 2.10); // entry-day low pierces 1.80

    app(TradeEngine::class)->sync();
    $trade = SignalTrade::first();

    expect($trade->status)->toBe('closed')
        ->and($trade->exit_reason)->toBe('stop')
        ->and($trade->exit_price)->toEqual(1.8)
        ->and($trade->exit_return)->toEqual(-0.10);
});

it('time-exits at the day-5 close when the stop never triggers', function () {
    tradeModel();
    $signal = tradeSignal(0.20);
    app(TradeEngine::class)->createForSignal($signal);

    tradeBar($signal->ticker_id, '2026-06-02', 2.00, 2.20, 1.95, 2.10);

    // Five post-entry sessions with a weekend gap (06/06–06/07).
    foreach (['2026-06-03', '2026-06-04', '2026-06-05', '2026-06-08', '2026-06-09'] as $i => $date) {
        $price = 2.20 + $i * 0.10;
        tradeBar($signal->ticker_id, $date, $price, $price + 0.05, $price - 0.05, $price);
    }

    app(TradeEngine::class)->sync();
    $trade = SignalTrade::first();

    expect($trade->status)->toBe('closed')
        ->and($trade->exit_reason)->toBe('time')
        ->and($trade->exit_date->toDateString())->toBe('2026-06-09')
        ->and($trade->exit_return)->toEqual(0.30)
        ->and($trade->net_return)->toEqual(0.25);
});

it('stays open with partial bars and cancels when no entry bar ever appears', function () {
    tradeModel();

    // Open position, only 2 bars so far: stays open.
    $signalA = tradeSignal(0.20);
    app(TradeEngine::class)->createForSignal($signalA);
    tradeBar($signalA->ticker_id, '2026-06-02', 2.00, 2.20, 1.95, 2.10);
    tradeBar($signalA->ticker_id, '2026-06-03', 2.10, 2.30, 2.05, 2.20);

    // Fired long ago, never got an entry bar: cancelled.
    $signalB = tradeSignal(0.20, now()->subDays(10)->toDateTimeString());
    app(TradeEngine::class)->createForSignal($signalB);

    app(TradeEngine::class)->sync();

    expect(SignalTrade::where('signal_id', $signalA->id)->first()->status)->toBe('open')
        ->and(SignalTrade::where('signal_id', $signalB->id)->first()->status)->toBe('cancelled');
});
