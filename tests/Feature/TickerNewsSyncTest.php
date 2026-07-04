<?php

use App\Jobs\Ingestion\SyncTickerNews;
use App\Models\Ticker;
use App\Models\TickerNews;
use App\Services\MarketData\PolygonClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function fakePolygonNews(): void
{
    Http::fake([
        'api.polygon.io/v2/reference/news*' => Http::response([
            'results' => [
                [
                    'id' => 'article-1',
                    'title' => 'Company signs huge deal',
                    'article_url' => 'https://example.com/deal',
                    'published_utc' => '2026-07-04T12:00:00Z',
                    'publisher' => ['name' => 'Newswire'],
                    'description' => 'A very big deal indeed.',
                    'image_url' => 'https://example.com/img.jpg',
                ],
                [
                    'id' => 'article-2',
                    'title' => 'Offering announced',
                    'article_url' => 'https://example.com/offering',
                    'published_utc' => '2026-07-03T09:00:00Z',
                ],
                [
                    // Malformed entry — must be skipped, not crash.
                    'title' => 'No id',
                ],
            ],
        ]),
    ]);
}

beforeEach(function () {
    config(['pennyhunt.polygon.api_key' => 'test-key']);
    Cache::flush();
});

it('upserts polygon news idempotently', function () {
    fakePolygonNews();

    $ticker = Ticker::create(['symbol' => 'NEWS', 'name' => 'Newsy Corp', 'is_active' => true]);

    (new SyncTickerNews($ticker->id))->handle(app(PolygonClient::class));
    (new SyncTickerNews($ticker->id))->handle(app(PolygonClient::class));

    expect(TickerNews::count())->toBe(2);

    $article = TickerNews::where('external_id', 'article-1')->first();

    expect($article->publisher)->toBe('Newswire')
        ->and($article->ticker_id)->toBe($ticker->id)
        ->and($article->published_at->toDateString())->toBe('2026-07-04');
});

it('respects the sync cooldown', function () {
    Queue::fake();

    $ticker = Ticker::create(['symbol' => 'COOL', 'name' => 'Cooldown Corp', 'is_active' => true]);

    expect(SyncTickerNews::dispatchIfStale($ticker->id))->toBeTrue()
        ->and(SyncTickerNews::dispatchIfStale($ticker->id))->toBeFalse();

    Queue::assertPushed(SyncTickerNews::class, 1);
});
