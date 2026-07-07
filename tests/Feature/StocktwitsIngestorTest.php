<?php

use App\Models\PostTickerMention;
use App\Models\RawPost;
use App\Models\Source;
use App\Models\Ticker;
use App\Services\Ingestion\StocktwitsIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->source = Source::create([
        'key' => 'stocktwits:trending',
        'type' => 'stocktwits',
        'name' => 'Stocktwits',
        'enabled' => true,
        'poll_interval_seconds' => 900,
        'config' => [],
    ]);

    $this->ticker = Ticker::create(['symbol' => 'ABCD', 'name' => 'Abcd Corp', 'exchange' => 'NASDAQ', 'is_active' => true]);
});

it('ingests messages and creates cashtag-tier mentions from structured symbol tags', function () {
    $ingested = app(StocktwitsIngestor::class)->ingest($this->source, [
        [
            'id' => 12345,
            'body' => 'This one is setting up beautifully, volume pouring in.',
            'created_at' => '2026-07-07T13:00:00Z',
            'user' => ['username' => 'pennyflow', 'followers' => 812, 'ideas' => 3400, 'official' => false],
            'symbols' => [['symbol' => 'ABCD'], ['symbol' => 'ZZZZZ']], // second not in universe
            'likes' => ['total' => 7],
            'entities' => ['sentiment' => ['basic' => 'Bullish']],
        ],
    ]);

    expect($ingested)->toBe(1);

    $post = RawPost::firstWhere('external_id', 'st_12345');
    expect($post)->not->toBeNull()
        ->and($post->score)->toBe(7)
        ->and($post->author->platform)->toBe('stocktwits')
        ->and($post->meta['sentiment'])->toBe('Bullish');

    $mention = PostTickerMention::firstWhere('raw_post_id', $post->id);
    expect($mention->ticker_id)->toBe($this->ticker->id)
        ->and($mention->method)->toBe('cashtag')
        ->and((float) $mention->confidence)->toBe(1.0)
        ->and(PostTickerMention::count())->toBe(1);
});

it('is idempotent per external id and skips tag-spam beyond six symbols', function () {
    $message = [
        'id' => 99,
        'body' => 'spam spam spam',
        'created_at' => '2026-07-07T13:00:00Z',
        'user' => ['username' => 'spammer', 'followers' => 1],
        'symbols' => collect(range(1, 9))->map(fn ($i) => ['symbol' => 'ABCD'])->all(),
    ];

    $ingestor = app(StocktwitsIngestor::class);
    expect($ingestor->ingest($this->source, [$message]))->toBe(1)
        ->and($ingestor->ingest($this->source, [$message]))->toBe(0)
        ->and(RawPost::count())->toBe(1);
});
