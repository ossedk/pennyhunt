<?php

use App\Models\ShortVolume;
use App\Models\Ticker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('parses Reg SHO files, filters to known tickers and computes the ratio', function () {
    Ticker::create(['symbol' => 'ABCD', 'name' => 'Abcd Inc', 'is_active' => true]);

    $nms = <<<'TXT'
    Date|Symbol|ShortVolume|ShortExemptVolume|TotalVolume|Market
    20260702|ABCD|600|0|1000|B,Q,N
    20260702|ZZZZ|100|0|200|Q
    TXT;

    $otc = <<<'TXT'
    Date|Symbol|ShortVolume|ShortExemptVolume|TotalVolume|Market
    20260702|ABCD|150|0|250|O
    TXT;

    Http::fake([
        'cdn.finra.org/equity/regsho/daily/CNMSshvol*' => Http::response($nms),
        'cdn.finra.org/equity/regsho/daily/CORFshvol*' => Http::response($otc),
    ]);

    $this->travelTo('2026-07-02 20:00:00');

    $this->artisan('pennyhunt:sync-short-volume --days=1')->assertSuccessful();

    // ZZZZ isn't in the universe — dropped. ABCD sums NMS + OTC facilities.
    expect(ShortVolume::count())->toBe(1);

    $row = ShortVolume::first();
    expect($row->short_volume)->toEqual(750.0)
        ->and($row->total_volume)->toEqual(1250.0)
        ->and($row->short_ratio)->toEqual(0.6)
        ->and($row->day->toDateString())->toBe('2026-07-02');
});

it('skips days that already have rows', function () {
    $ticker = Ticker::create(['symbol' => 'ABCD', 'name' => 'Abcd Inc', 'is_active' => true]);

    ShortVolume::create([
        'ticker_id' => $ticker->id, 'day' => '2026-07-02',
        'short_volume' => 1, 'total_volume' => 2, 'short_ratio' => 0.5,
    ]);

    Http::fake();
    $this->travelTo('2026-07-02 20:00:00');

    $this->artisan('pennyhunt:sync-short-volume --days=1')->assertSuccessful();

    Http::assertNothingSent();
});
