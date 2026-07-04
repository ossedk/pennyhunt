<?php

use App\Models\Author;
use App\Models\PostTickerMention;
use App\Models\RawPost;
use App\Models\SecFiling;
use App\Models\Source;
use App\Models\Ticker;
use App\Models\TickerShareCount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('stores dilution-relevant filings and share counts from EDGAR', function () {
    $ticker = Ticker::create(['symbol' => 'DILU', 'cik' => 12345, 'name' => 'Dilution Corp', 'is_active' => true]);

    // The command targets mentioned tickers only.
    $source = Source::create([
        'key' => 'reddit:test', 'type' => 'reddit', 'name' => 'r/test',
        'enabled' => true, 'poll_interval_seconds' => 120, 'config' => [],
    ]);
    $author = Author::create(['platform' => 'reddit', 'username' => 'u1', 'stats' => []]);

    foreach ([1, 2] as $i) {
        $post = RawPost::create([
            'source_id' => $source->id, 'external_id' => "t3_{$i}", 'kind' => 'post',
            'author_id' => $author->id, 'title' => 'x', 'body' => 'y', 'score' => 1,
            'num_comments' => 0, 'posted_at' => now()->subDays($i), 'ingested_at' => now(),
            'meta' => [],
        ]);
        PostTickerMention::create([
            'raw_post_id' => $post->id, 'ticker_id' => $ticker->id,
            'method' => 'cashtag', 'confidence' => 1.0, 'posted_at' => now()->subDays($i),
        ]);
    }

    Http::fake([
        'data.sec.gov/submissions/*' => Http::response([
            'filings' => ['recent' => [
                'form' => ['S-3', '424B5', '10-Q', 'SC 13G'], // SC 13G is not stored
                'filingDate' => ['2026-01-10', '2026-03-01', '2026-05-01', '2026-05-02'],
                'accessionNumber' => ['acc-1', 'acc-2', 'acc-3', 'acc-4'],
            ]],
        ]),
        'data.sec.gov/api/xbrl/companyconcept/*' => Http::response([
            'units' => ['shares' => [
                ['end' => '2025-03-31', 'val' => 10_000_000],
                ['end' => '2026-03-31', 'val' => 15_000_000],
            ]],
        ]),
    ]);

    $this->artisan('pennyhunt:sync-sec-filings')->assertSuccessful();

    expect(SecFiling::where('ticker_id', $ticker->id)->pluck('form')->sort()->values()->all())
        ->toBe(['10-Q', '424B5', 'S-3'])
        ->and(TickerShareCount::where('ticker_id', $ticker->id)->count())->toBe(2)
        ->and($ticker->refresh()->meta['edgar_synced_at'])->not->toBeNull();
});
