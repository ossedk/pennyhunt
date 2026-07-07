<?php

use App\Models\Signal;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['pennyhunt.polygon.api_key' => 'test']);

    $this->user = User::factory()->create();
    $this->ticker = Ticker::create(['symbol' => 'INTR', 'name' => 'Intraday Corp', 'is_active' => true]);

    // Two fake hourly bars: 2026-06-01 14:00 & 15:00 UTC.
    Http::fake([
        'api.polygon.io/*' => Http::response(['results' => [
            ['t' => strtotime('2026-06-01 14:00:00 UTC') * 1000, 'o' => 1.0, 'h' => 1.2, 'l' => 0.9, 'c' => 1.1, 'v' => 50000],
            ['t' => strtotime('2026-06-01 15:00:00 UTC') * 1000, 'o' => 1.1, 'h' => 1.4, 'l' => 1.0, 'c' => 1.3, 'v' => 80000],
        ]]),
    ]);
});

it('serves intraday bars for a signal with the fire marked at its exact time', function () {
    $signal = Signal::create([
        'ticker_id' => $this->ticker->id,
        'fired_at' => '2026-06-01 15:00:00',
        'composite_score' => 0.8,
        'confidence' => 0.2,
        'breakdown' => [],
        'state' => 'new',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/signals/{$signal->id}/intraday?interval=1h")
        ->assertOk()
        ->json();

    expect($response['bars'])->toHaveCount(2)
        // 14:00 UTC on 2026-06-01 (EDT, UTC-4) renders as 10:00 ET wall clock.
        ->and($response['bars'][0]['time'])->toBe(strtotime('2026-06-01 10:00:00 UTC'))
        ->and($response['markers'][0]['label'])->toBe('fired')
        ->and($response['markers'][0]['time'])->toBe(strtotime('2026-06-01 11:00:00 UTC'));
});

it('serves intraday bars for a ticker with signal fires marked', function () {
    Signal::create([
        'ticker_id' => $this->ticker->id,
        'fired_at' => now()->subDay(),
        'composite_score' => 0.8,
        'confidence' => 0.2,
        'breakdown' => [],
        'state' => 'new',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/tickers/INTR/intraday?interval=5m')
        ->assertOk()
        ->json();

    expect($response['interval'])->toBe('5m')
        ->and($response['bars'])->toHaveCount(2)
        ->and($response['markers'])->toHaveCount(1);
});
