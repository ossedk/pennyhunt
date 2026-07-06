<?php

use App\Models\Ticker;
use App\Services\Features\MarketIntelligence;
use App\Services\MarketData\SqueezeFuelClients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('computes squeeze features honoring publication lags', function () {
    $ticker = Ticker::create(['symbol' => 'SQZE', 'name' => 'Squeeze Corp', 'is_active' => true]);

    // SI settled 40 and 20 days ago: both visible (lag 13d); latest doubles the prior.
    DB::table('short_interest')->insert([
        ['ticker_id' => $ticker->id, 'settlement_date' => now()->subDays(40)->toDateString(), 'shares_short' => 1_000_000, 'days_to_cover' => 2.5, 'created_at' => now(), 'updated_at' => now()],
        ['ticker_id' => $ticker->id, 'settlement_date' => now()->subDays(20)->toDateString(), 'shares_short' => 2_000_000, 'days_to_cover' => 5.0, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // FTD settled 30 days ago (visible at 21d lag); one settled 5 days ago (NOT visible).
    DB::table('ftd_reports')->insert([
        ['ticker_id' => $ticker->id, 'settlement_date' => now()->subDays(30)->toDateString(), 'fails_quantity' => 99_999, 'price' => 1.5, 'created_at' => now(), 'updated_at' => now()],
        ['ticker_id' => $ticker->id, 'settlement_date' => now()->subDays(5)->toDateString(), 'fails_quantity' => 10_000_000, 'price' => 1.5, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('borrow_rates')->insert([
        ['ticker_id' => $ticker->id, 'day' => now()->subDays(2)->toDateString(), 'fee' => 45.5, 'available' => 5000, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('trade_halts')->insert([
        ['symbol' => 'SQZE', 'ticker_id' => $ticker->id, 'halted_at' => now()->subDays(2), 'resumed_at' => now()->subDays(2)->addMinutes(10), 'reason' => 'LUDP', 'created_at' => now(), 'updated_at' => now()],
        ['symbol' => 'SQZE', 'ticker_id' => $ticker->id, 'halted_at' => now()->subDays(30), 'resumed_at' => null, 'reason' => 'LUDP', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $today = now()->toDateString();
    $f = MarketIntelligence::load([$ticker->id], $today, $today)->features($ticker->id, $today);

    expect($f['si_days_to_cover'])->toEqual(5.0)
        ->and($f['si_pct_change'])->toEqual(1.0)          // 2M vs 1M = +100%
        ->and($f['ftd_log'])->toEqualWithDelta(5.0, 0.01)  // log10(1+99,999) ≈ 5 — the fresh 10M row is not visible yet
        ->and($f['borrow_fee'])->toEqual(45.5)
        ->and($f['halted_5d'])->toBe(1);                   // only the halt 2 days ago counts
});

it('parses the SEC FTD pipe format and skips trailer rows', function () {
    $text = "SETTLEMENT DATE|CUSIP|SYMBOL|QUANTITY (FAILS)|DESCRIPTION|PRICE\n"
        ."20260615|123456789|ABCD|54321|ABCD CORP|1.23\n"
        ."20260615|987654321|WXYZ|100|WXYZ INC|.\n"
        ."Trailer record count 2\n";

    $zipPath = tempnam(sys_get_temp_dir(), 'ftdz');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $zip->addFromString('cnsfails202606a.txt', $text);
    $zip->close();

    Http::fake([
        'sec.gov/*' => Http::response(file_get_contents($zipPath)),
    ]);

    $rows = app(SqueezeFuelClients::class)->secFailsToDeliver('202606a');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['symbol'])->toBe('ABCD')
        ->and($rows[0]['settlement_date'])->toBe('2026-06-15')
        ->and($rows[0]['fails'])->toBe(54321)
        ->and($rows[0]['price'])->toEqual(1.23)
        ->and($rows[1]['price'])->toBeNull();

    unlink($zipPath);
});
