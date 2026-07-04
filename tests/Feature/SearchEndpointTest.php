<?php

use App\Models\Author;
use App\Models\PostTickerMention;
use App\Models\RawPost;
use App\Models\Source;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function searchTicker(string $symbol, string $name, ?int $marketCap = null): Ticker
{
    return Ticker::create([
        'symbol' => $symbol,
        'name' => $name,
        'is_active' => true,
        'market_cap' => $marketCap,
    ]);
}

function mention(Ticker $ticker, int $count): void
{
    $source = Source::firstOrCreate(
        ['key' => 'reddit:searchtest'],
        ['type' => 'reddit', 'name' => 'r/searchtest', 'enabled' => true, 'poll_interval_seconds' => 120, 'config' => []],
    );
    $author = Author::firstOrCreate(['platform' => 'reddit', 'username' => 'searcher'], ['stats' => []]);

    for ($i = 0; $i < $count; $i++) {
        $post = RawPost::create([
            'source_id' => $source->id,
            'author_id' => $author->id,
            'external_id' => 'search-'.$ticker->symbol.'-'.$i,
            'kind' => 'post',
            'title' => 'buzz',
            'body' => 'buzz',
            'posted_at' => now()->subHours(2),
            'ingested_at' => now(),
            'meta' => [],
        ]);

        PostTickerMention::create([
            'raw_post_id' => $post->id,
            'ticker_id' => $ticker->id,
            'method' => 'cashtag',
            'confidence' => 1,
            'posted_at' => now()->subHours(2),
        ]);
    }
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('ranks exact symbol matches first, then attention', function () {
    searchTicker('SOFI', 'SoFi Technologies');
    searchTicker('SOFX', 'Sofix Industries', 9_000_000_000); // name match, big cap, quiet
    $loud = searchTicker('QQQZ', 'Sofi Lookalike Corp', 5_000_000); // name match, small cap, loud

    mention($loud, 3);

    $results = $this->getJson(route('search', ['q' => 'sofi']))
        ->assertOk()
        ->json('results');

    // Exact symbol always wins; among name matches, 24h attention beats size.
    expect(array_column($results, 'symbol'))->toBe(['SOFI', 'QQQZ', 'SOFX'])
        ->and($results[1]['mentions_24h'])->toBe(3);
});

it('matches company names case-insensitively', function () {
    searchTicker('GME', 'GameStop Corp');

    $results = $this->getJson(route('search', ['q' => 'gamestop']))->json('results');

    expect($results)->toHaveCount(1)
        ->and($results[0]['symbol'])->toBe('GME');
});

it('returns nothing for an empty query', function () {
    $this->getJson(route('search', ['q' => ' ']))
        ->assertOk()
        ->assertExactJson(['results' => []]);
});

it('excludes inactive tickers', function () {
    Ticker::create(['symbol' => 'DEAD', 'name' => 'Delisted Corp', 'is_active' => false]);

    expect($this->getJson(route('search', ['q' => 'DEAD']))->json('results'))->toBeEmpty();
});
