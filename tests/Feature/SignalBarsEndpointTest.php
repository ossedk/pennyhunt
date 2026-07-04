<?php

use App\Models\MarketBar;
use App\Models\Signal;
use App\Models\Ticker;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns bars and trade-discipline annotations for a signal', function () {
    $this->actingAs(User::factory()->create());

    $ticker = Ticker::create(['symbol' => 'CHRT', 'name' => 'Chart Corp', 'is_active' => true]);

    $firedAt = CarbonImmutable::parse('2026-03-10 14:00:00');

    $signal = Signal::create([
        'ticker_id' => $ticker->id,
        'fired_at' => $firedAt,
        'composite_score' => 0.8,
        'breakdown' => [],
        'state' => 'new',
    ]);

    // Weekday-only bars around the fire date, entry open $2.00 the day after.
    foreach (range(-10, 15) as $offset) {
        $date = $firedAt->addDays($offset);

        if ($date->isWeekend()) {
            continue;
        }

        MarketBar::create([
            'ticker_id' => $ticker->id,
            'interval' => '1d',
            'bucket_start' => $date->startOfDay(),
            'open' => 2.0,
            'high' => 2.1,
            'low' => 1.9,
            'close' => 2.05,
            'volume' => 100000,
        ]);
    }

    $response = $this->getJson(route('signals.bars', $signal))->assertOk();

    expect($response->json('symbol'))->toBe('CHRT')
        ->and($response->json('fired_date'))->toBe('2026-03-10')
        ->and($response->json('bars'))->not->toBeEmpty()
        ->and($response->json('entry_date'))->toBe('2026-03-11')
        ->and($response->json('entry'))->toEqual(2.0)
        ->and($response->json('stop_level'))->toEqual(1.8)
        ->and($response->json('time_exit_date'))->not->toBeNull();
});

it('requires authentication', function () {
    $ticker = Ticker::create(['symbol' => 'CHRT', 'name' => 'Chart Corp', 'is_active' => true]);

    $signal = Signal::create([
        'ticker_id' => $ticker->id,
        'fired_at' => now(),
        'composite_score' => 0.8,
        'breakdown' => [],
        'state' => 'new',
    ]);

    $this->get(route('signals.bars', $signal))->assertRedirect(route('login'));
});
