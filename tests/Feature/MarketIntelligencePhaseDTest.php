<?php

use App\Models\InsiderTrade;
use App\Models\Ticker;
use App\Models\TickerNews;
use App\Services\Features\MarketIntelligence;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('computes point-in-time insider flow by filed date', function () {
    $ticker = Ticker::create(['symbol' => 'ABCD', 'name' => 'Abcd Corp', 'is_active' => true]);

    // Officer bought $50k, filed 10 days ago; sold $10k, filed 5 days ago.
    InsiderTrade::create([
        'ticker_id' => $ticker->id, 'accession' => 'a1', 'seq' => 1,
        'filed_at' => now()->subDays(10)->toDateString(), 'code' => 'P',
        'shares' => 50000, 'price' => 1.0, 'value' => 50000, 'is_officer' => true,
    ]);
    InsiderTrade::create([
        'ticker_id' => $ticker->id, 'accession' => 'a2', 'seq' => 1,
        'filed_at' => now()->subDays(5)->toDateString(), 'code' => 'S',
        'shares' => 10000, 'price' => 1.0, 'value' => 10000, 'is_officer' => true,
    ]);

    $today = now()->toDateString();
    $intel = MarketIntelligence::load([$ticker->id], $today, $today);
    $f = $intel->features($ticker->id, $today);

    expect($f['insider_buys_90d'])->toBe(1)
        // net +$40k → log10(40001) ≈ 4.6
        ->and($f['insider_net_value_90d'])->toBeGreaterThan(4.5)->toBeLessThan(4.7);

    // As-of a day BEFORE the buy was filed: nothing visible.
    $earlier = now()->subDays(11)->toDateString();
    $f2 = MarketIntelligence::load([$ticker->id], $earlier, $earlier)->features($ticker->id, $earlier);

    expect($f2['insider_buys_90d'])->toBe(0);
});

it('flags catalyst and offering news within the 7-day window', function () {
    $ticker = Ticker::create(['symbol' => 'ABCD', 'name' => 'Abcd Corp', 'is_active' => true]);

    TickerNews::create([
        'ticker_id' => $ticker->id, 'external_id' => 'c1',
        'title' => 'FDA clearance', 'article_url' => 'https://x.test/1',
        'published_at' => now()->subDays(2), 'catalyst_type' => 'fda',
        'catalyst_classified_at' => now(),
    ]);
    TickerNews::create([
        'ticker_id' => $ticker->id, 'external_id' => 'c2',
        'title' => 'Registered direct offering', 'article_url' => 'https://x.test/2',
        'published_at' => now()->subDays(3), 'catalyst_type' => 'offering',
        'catalyst_classified_at' => now(),
    ]);
    TickerNews::create([
        'ticker_id' => $ticker->id, 'external_id' => 'c3',
        'title' => 'Stock commentary', 'article_url' => 'https://x.test/3',
        'published_at' => now()->subDays(1), 'catalyst_type' => 'none',
        'catalyst_classified_at' => now(),
    ]);

    $today = now()->toDateString();
    $f = MarketIntelligence::load([$ticker->id], $today, $today)->features($ticker->id, $today);

    expect($f['news_catalyst_7d'])->toBeTrue()
        ->and($f['news_offering_7d'])->toBeTrue();

    // 10 days later both fall out of the window.
    $later = now()->addDays(10)->toDateString();
    $f2 = MarketIntelligence::load([$ticker->id], $later, $later)->features($ticker->id, $later);

    expect($f2['news_catalyst_7d'])->toBeFalse()
        ->and($f2['news_offering_7d'])->toBeFalse();
});
