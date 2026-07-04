<?php

use App\Models\AlertEvent;
use App\Models\MarketBar;
use App\Models\SecFiling;
use App\Models\Signal;
use App\Models\SignalTrade;
use App\Models\Ticker;
use App\Models\TickerMetric;
use App\Services\Trading\TradeAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function alertTrade(array $overrides = []): SignalTrade
{
    $ticker = Ticker::create(['symbol' => 'ALR'.random_int(10, 99), 'name' => 'Alert Test', 'is_active' => true]);

    $signal = Signal::create([
        'ticker_id' => $ticker->id,
        'fired_at' => now()->subDays(4),
        'composite_score' => 0.8,
        'confidence' => 0.2,
        'breakdown' => [],
        'state' => 'new',
    ]);

    return SignalTrade::create([
        'signal_id' => $signal->id,
        'ticker_id' => $ticker->id,
        'status' => 'open',
        'tier' => 'trade',
        'entry_date' => now()->subDays(3)->toDateString(),
        'entry_price' => 2.0,
        'stop_price' => 1.8,
        ...$overrides,
    ]);
}

it('alerts when the quote is within 3% of the stop, once per day', function () {
    $trade = alertTrade(['last_quote' => 1.84, 'last_quote_at' => now()]);

    app(TradeAlerts::class)->checkQuote($trade);
    app(TradeAlerts::class)->checkQuote($trade); // same day: deduped

    expect(AlertEvent::where('kind', 'trade_stop_proximity')->count())->toBe(1)
        ->and(AlertEvent::first()->payload['distance'])->toBeLessThanOrEqual(0.03);
});

it('does not alert when the quote is comfortably above the stop', function () {
    $trade = alertTrade(['last_quote' => 2.4, 'last_quote_at' => now()]);

    app(TradeAlerts::class)->checkQuote($trade);

    expect(AlertEvent::count())->toBe(0);
});

it('alerts on dilution filings since entry and on the eve of the time exit', function () {
    $trade = alertTrade();

    // 4 completed bars after entry -> holding day 4 = eve of the day-5 exit.
    foreach (range(0, 4) as $i) {
        MarketBar::create([
            'ticker_id' => $trade->ticker_id, 'interval' => '1d',
            'bucket_start' => now()->subDays(3 - $i)->toDateString().' 00:00:00',
            'open' => 2.0, 'high' => 2.2, 'low' => 1.95, 'close' => 2.1, 'volume' => 1_000_000,
        ]);
    }

    SecFiling::create([
        'ticker_id' => $trade->ticker_id,
        'form' => '424B5',
        'filed_at' => now()->subDay(),
        'accession' => 'test-'.$trade->id,
    ]);

    app(TradeAlerts::class)->checkOpenTrades();

    expect(AlertEvent::where('kind', 'trade_new_filing')->count())->toBe(1)
        ->and(AlertEvent::where('kind', 'trade_time_exit_next')->count())->toBe(1);
});

it('alerts when daily mentions collapse from the fire day', function () {
    $trade = alertTrade();

    TickerMetric::create([
        'ticker_id' => $trade->ticker_id, 'interval' => '1d',
        'bucket_start' => now()->subDays(4)->toDateString().' 00:00:00',
        'mention_count' => 40, 'unique_authors' => 20,
    ]);

    TickerMetric::create([
        'ticker_id' => $trade->ticker_id, 'interval' => '1d',
        'bucket_start' => now()->subDay()->toDateString().' 00:00:00',
        'mention_count' => 3, 'unique_authors' => 2,
    ]);

    app(TradeAlerts::class)->checkOpenTrades();

    expect(AlertEvent::where('kind', 'trade_mention_collapse')->count())->toBe(1);
});
