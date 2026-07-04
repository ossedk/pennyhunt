<?php

use App\Services\MarketData\MarketClock;
use App\Services\MarketData\PolygonClient;
use Illuminate\Support\Facades\Cache;

function clockWithPolygon(?array $payload): MarketClock
{
    $polygon = Mockery::mock(PolygonClient::class);
    $polygon->shouldReceive('marketStatus')->andReturn($payload);

    return new MarketClock($polygon);
}

beforeEach(fn () => Cache::flush());

it('normalizes polygon market status payloads', function (array $payload, string $expected) {
    expect(clockWithPolygon($payload)->status())
        ->toMatchArray(['status' => $expected, 'source' => 'polygon']);
})->with([
    'open' => [['market' => 'open', 'earlyHours' => false, 'afterHours' => false], 'open'],
    'pre-market' => [['market' => 'extended-hours', 'earlyHours' => true, 'afterHours' => false], 'early_hours'],
    'after-hours' => [['market' => 'extended-hours', 'earlyHours' => false, 'afterHours' => true], 'after_hours'],
    'closed' => [['market' => 'closed', 'earlyHours' => false, 'afterHours' => false], 'closed'],
]);

it('falls back to the NYSE schedule when polygon is unavailable', function (string $utcNow, string $expected) {
    $this->travelTo($utcNow);

    expect(clockWithPolygon(null)->status())
        ->toMatchArray(['status' => $expected, 'source' => 'schedule']);
})->with([
    // 2026-07-08 is a Wednesday. 14:30 UTC = 10:30 ET (EDT).
    'regular session' => ['2026-07-08 14:30:00', 'open'],
    'pre-market' => ['2026-07-08 12:00:00', 'early_hours'],
    'after hours' => ['2026-07-08 21:00:00', 'after_hours'],
    'overnight' => ['2026-07-08 03:00:00', 'closed'],
    'weekend' => ['2026-07-11 15:00:00', 'closed'],
]);
