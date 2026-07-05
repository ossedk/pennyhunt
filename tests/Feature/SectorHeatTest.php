<?php

use App\Models\Ticker;
use App\Services\Features\SectorHeat;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sectorBars(float $startPrice, float $endPrice, int $sessions = 10): array
{
    $bars = [];

    for ($i = 0; $i < $sessions; $i++) {
        $price = $startPrice + ($endPrice - $startPrice) * $i / max($sessions - 1, 1);
        $bars[] = [
            'date' => gmdate('Y-m-d', strtotime('2025-06-01 UTC') + $i * 86400),
            'open' => $price, 'high' => $price, 'low' => $price, 'close' => $price,
            'volume' => 100000.0,
        ];
    }

    return $bars;
}

it('measures the share of hot sector peers excluding self', function () {
    $tickers = collect(['AAAA', 'BBBB', 'CCCC', 'DDDD', 'EEEE'])
        ->map(fn (string $s) => Ticker::create([
            'symbol' => $s, 'name' => $s.' Corp', 'is_active' => true, 'sic_code' => '2836',
        ]));

    $day = gmdate('Y-m-d', strtotime('2025-06-10 UTC'));

    // AAAA (self) flat; BBBB and CCCC ripped >20% over 5 sessions; DDDD, EEEE flat.
    $bars = [
        $tickers[0]->id => sectorBars(1.0, 1.0),
        $tickers[1]->id => sectorBars(1.0, 2.0),
        $tickers[2]->id => sectorBars(1.0, 1.6),
        $tickers[3]->id => sectorBars(1.0, 1.02),
        $tickers[4]->id => sectorBars(1.0, 0.9),
    ];

    $heat = SectorHeat::load($tickers->pluck('id')->all(), $bars, '2025-06-01', '2025-06-12');

    $f = $heat->features($tickers[0]->id, $day);

    // 2 hot peers out of 4 (self excluded) = 0.5
    expect($f['sector_heat'])->toEqual(0.5);
});

it('returns nulls for tickers without a sic code', function () {
    $ticker = Ticker::create(['symbol' => 'NOSIC', 'name' => 'No Sic', 'is_active' => true]);

    $heat = SectorHeat::load([$ticker->id], [], '2025-06-01', '2025-06-12');

    expect($heat->features($ticker->id, '2025-06-10'))
        ->toBe(['sector_heat' => null, 'sector_mention_z' => null]);
});
