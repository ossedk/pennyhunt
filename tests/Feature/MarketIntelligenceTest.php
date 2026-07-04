<?php

use App\Models\Author;
use App\Models\MarketBar;
use App\Models\PostTickerMention;
use App\Models\RawPost;
use App\Models\SecFiling;
use App\Models\ShortVolume;
use App\Models\Source;
use App\Models\Ticker;
use App\Models\TickerShareCount;
use App\Services\Features\MarketIntelligence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function intelTicker(): Ticker
{
    return Ticker::create(['symbol' => 'DILU', 'name' => 'Dilution Corp', 'is_active' => true]);
}

it('computes dilution features as-of the query day with no look-ahead', function () {
    $ticker = intelTicker();

    SecFiling::create(['ticker_id' => $ticker->id, 'form' => 'S-3', 'filed_at' => '2026-01-10', 'accession' => 'a-1']);
    SecFiling::create(['ticker_id' => $ticker->id, 'form' => '424B5', 'filed_at' => '2026-03-01', 'accession' => 'a-2']);

    $intel = MarketIntelligence::load([$ticker->id], '2025-12-01', '2026-08-01');

    // Before the shelf was filed: nothing on record.
    expect($intel->features($ticker->id, '2026-01-09')['active_shelf'])->toBeFalse()
        ->and($intel->features($ticker->id, '2026-01-09')['atm_filed_90d'])->toBeFalse();

    // After both filings: shelf active, ATM takedown fresh.
    $after = $intel->features($ticker->id, '2026-03-15');
    expect($after['active_shelf'])->toBeTrue()
        ->and($after['atm_filed_90d'])->toBeTrue();

    // 91+ days after the 424B5: takedown window expired, shelf still active.
    $later = $intel->features($ticker->id, '2026-06-15');
    expect($later['atm_filed_90d'])->toBeFalse()
        ->and($later['active_shelf'])->toBeTrue();
});

it('computes 12-month share growth from point-in-time observations', function () {
    $ticker = intelTicker();

    TickerShareCount::create(['ticker_id' => $ticker->id, 'as_of' => '2025-03-31', 'shares' => 10_000_000]);
    TickerShareCount::create(['ticker_id' => $ticker->id, 'as_of' => '2026-03-31', 'shares' => 15_000_000]);

    $intel = MarketIntelligence::load([$ticker->id], '2025-01-01', '2026-08-01');

    // +50% dilution over 12 months.
    expect($intel->features($ticker->id, '2026-04-15')['share_growth_12m'])->toEqualWithDelta(0.5, 0.001);

    // Before the second observation there is no >=12mo-apart pair.
    expect($intel->features($ticker->id, '2025-06-01')['share_growth_12m'])->toBeNull();
});

it('returns the latest short ratio at or before the day, max 6 days stale', function () {
    $ticker = intelTicker();

    ShortVolume::create([
        'ticker_id' => $ticker->id, 'day' => '2026-03-20',
        'short_volume' => 600, 'total_volume' => 1000, 'short_ratio' => 0.6,
    ]);

    $intel = MarketIntelligence::load([$ticker->id], '2026-03-01', '2026-04-30');

    expect($intel->features($ticker->id, '2026-03-20')['short_ratio'])->toEqual(0.6)
        ->and($intel->features($ticker->id, '2026-03-24')['short_ratio'])->toEqual(0.6) // 4 days stale: ok
        ->and($intel->features($ticker->id, '2026-03-30')['short_ratio'])->toBeNull()   // too stale
        ->and($intel->features($ticker->id, '2026-03-19')['short_ratio'])->toBeNull();  // look-ahead guard
});

it('computes benchmark 5-session momentum and site-wide mention z', function () {
    $ticker = intelTicker();
    $iwm = Ticker::create(['symbol' => 'IWM', 'name' => 'Benchmark', 'is_active' => true, 'is_ambiguous' => true]);

    // 10 benchmark sessions climbing 1% per day (weekdays from 2026-03-02).
    $day = CarbonImmutable::parse('2026-03-02');
    $price = 100.0;

    for ($i = 0; $i < 12; $i++, $day = $day->addDay()) {
        if ($day->isWeekend()) {
            continue;
        }

        $price *= 1.01;
        MarketBar::create([
            'ticker_id' => $iwm->id, 'interval' => '1d', 'bucket_start' => $day->setTime(0, 0),
            'open' => $price, 'high' => $price, 'low' => $price, 'close' => $price, 'volume' => 1,
        ]);
    }

    // Site mentions: 8/12 alternating baseline for 30 days, then a 40-mention day.
    $source = Source::create([
        'key' => 'reddit:test', 'type' => 'reddit', 'name' => 'r/test',
        'enabled' => true, 'poll_interval_seconds' => 120, 'config' => [],
    ]);
    $author = Author::create(['platform' => 'reddit', 'username' => 'siteuser', 'stats' => []]);

    $mentionDay = CarbonImmutable::parse('2026-02-14');

    for ($d = 0; $d < 31; $d++, $mentionDay = $mentionDay->addDay()) {
        $count = $d === 30 ? 40 : ($d % 2 === 0 ? 8 : 12);

        for ($m = 0; $m < $count; $m++) {
            $post = RawPost::create([
                'source_id' => $source->id, 'external_id' => 't3_'.uniqid('', true), 'kind' => 'post',
                'author_id' => $author->id, 'title' => 'x', 'body' => 'y', 'score' => 1,
                'num_comments' => 0, 'posted_at' => $mentionDay->setTime(12, 0), 'ingested_at' => $mentionDay,
                'meta' => [],
            ]);

            PostTickerMention::create([
                'raw_post_id' => $post->id, 'ticker_id' => $ticker->id,
                'method' => 'cashtag', 'confidence' => 1.0, 'posted_at' => $mentionDay->setTime(12, 0),
            ]);
        }
    }

    $intel = MarketIntelligence::load([$ticker->id], '2026-03-01', '2026-03-31');

    // 5 sessions at +1%/day => ~+5.1%.
    expect($intel->features($ticker->id, '2026-03-16')['market_ret_5d'])->toEqualWithDelta(0.051, 0.002);

    // The 40-mention day (2026-03-16) sits far above the flat-10 baseline.
    expect($intel->features($ticker->id, '2026-03-16')['site_mention_z'])->toBeGreaterThan(3.0);

    // A day with zero coverage in the archive window yields null (not fake 0).
    $empty = MarketIntelligence::load([$ticker->id], '2020-01-01', '2020-01-31');
    expect($empty->features($ticker->id, '2020-01-15')['site_mention_z'])->toBeNull();
});

it('computes macro features (vix level, btc 5-session return) as-of the day', function () {
    $ticker = intelTicker();
    $vix = Ticker::create(['symbol' => '^VIX', 'name' => 'VIX', 'is_active' => true, 'is_ambiguous' => true]);
    $btc = Ticker::create(['symbol' => 'BTC-USD', 'name' => 'BTC', 'is_active' => true, 'is_ambiguous' => true]);

    // VIX at 18 for a week, spiking to 35 on 2026-03-13.
    // BTC climbing 2%/day (trades every calendar day).
    $day = CarbonImmutable::parse('2026-03-02');
    $btcPrice = 50_000.0;

    for ($i = 0; $i < 12; $i++, $day = $day->addDay()) {
        $btcPrice *= 1.02;
        MarketBar::create([
            'ticker_id' => $btc->id, 'interval' => '1d', 'bucket_start' => $day->setTime(0, 0),
            'open' => $btcPrice, 'high' => $btcPrice, 'low' => $btcPrice, 'close' => $btcPrice, 'volume' => 1,
        ]);

        if ($day->isWeekend()) {
            continue;
        }

        $level = $day->toDateString() === '2026-03-13' ? 35.0 : 18.0;
        MarketBar::create([
            'ticker_id' => $vix->id, 'interval' => '1d', 'bucket_start' => $day->setTime(0, 0),
            'open' => $level, 'high' => $level, 'low' => $level, 'close' => $level, 'volume' => 0,
        ]);
    }

    $intel = MarketIntelligence::load([$ticker->id], '2026-03-01', '2026-03-31');

    expect($intel->features($ticker->id, '2026-03-12')['vix'])->toEqual(18.0)
        ->and($intel->features($ticker->id, '2026-03-13')['vix'])->toEqual(35.0)
        // Weekend: carries the Friday spike forward, no look-ahead.
        ->and($intel->features($ticker->id, '2026-03-14')['vix'])->toEqual(35.0)
        // 5 sessions at +2%/day => ~+10.4%.
        ->and($intel->features($ticker->id, '2026-03-13')['btc_ret_5d'])->toEqualWithDelta(0.104, 0.002);
});

it('counts consecutive rising-mention days as the momentum streak', function () {
    $ticker = intelTicker();
    $source = Source::create([
        'key' => 'reddit:streak', 'type' => 'reddit', 'name' => 'r/streak',
        'enabled' => true, 'poll_interval_seconds' => 120, 'config' => [],
    ]);
    $author = Author::create(['platform' => 'reddit', 'username' => 'streakuser', 'stats' => []]);

    // Mentions build 2 -> 5 -> 9 over 2026-03-10..12, then drop to 4 on the 13th.
    foreach (['2026-03-10' => 2, '2026-03-11' => 5, '2026-03-12' => 9, '2026-03-13' => 4] as $date => $count) {
        for ($m = 0; $m < $count; $m++) {
            $post = RawPost::create([
                'source_id' => $source->id, 'external_id' => 't3_'.uniqid('', true), 'kind' => 'post',
                'author_id' => $author->id, 'title' => 'x', 'body' => 'y', 'score' => 1,
                'num_comments' => 0, 'posted_at' => $date.' 12:00:00', 'ingested_at' => now(),
                'meta' => [],
            ]);

            PostTickerMention::create([
                'raw_post_id' => $post->id, 'ticker_id' => $ticker->id,
                'method' => 'cashtag', 'confidence' => 1.0, 'posted_at' => $date.' 12:00:00',
            ]);
        }
    }

    $intel = MarketIntelligence::load([$ticker->id], '2026-03-01', '2026-03-31');

    // 10th: 2 > 0 (prior silence) => streak 1. 12th: three rising days.
    expect($intel->features($ticker->id, '2026-03-10')['mention_streak'])->toBe(1)
        ->and($intel->features($ticker->id, '2026-03-11')['mention_streak'])->toBe(2)
        ->and($intel->features($ticker->id, '2026-03-12')['mention_streak'])->toBe(3)
        // 13th: 4 < 9 — momentum broken.
        ->and($intel->features($ticker->id, '2026-03-13')['mention_streak'])->toBe(0);
});
