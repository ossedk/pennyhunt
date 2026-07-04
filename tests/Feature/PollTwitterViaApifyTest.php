<?php

use App\Events\FeedUpdated;
use App\Jobs\Ingestion\PollTwitterViaApify;
use App\Jobs\Pipeline\ProcessRawPost;
use App\Models\Author;
use App\Models\PostTickerMention;
use App\Models\RawPost;
use App\Models\Source;
use App\Models\Ticker;
use App\Services\Ingestion\ApifyClient;
use App\Services\Ingestion\TwitterIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function runTwitterJob(): void
{
    (new PollTwitterViaApify)->handle(app(ApifyClient::class), app(TwitterIngestor::class));
}

beforeEach(function () {
    config()->set('pennyhunt.apify.token', 'test-token');
    config()->set('pennyhunt.apify.twitter.enabled', true);

    $this->source = Source::create([
        'key' => 'twitter:cashtags',
        'type' => 'twitter',
        'name' => 'X/Twitter (trending cashtags)',
        'enabled' => true,
        'poll_interval_seconds' => 3600,
        'config' => [],
    ]);

    // A ticker trending on Reddit (3+ mentions in the last 24h).
    $reddit = Source::create([
        'key' => 'reddit:pennystocks', 'type' => 'reddit', 'name' => 'r/pennystocks',
        'enabled' => true, 'poll_interval_seconds' => 120, 'config' => ['subreddit' => 'pennystocks'],
    ]);
    $ticker = Ticker::create(['symbol' => 'ABCD', 'name' => 'Abcd Corp', 'is_active' => true]);

    for ($i = 0; $i < 3; $i++) {
        $post = RawPost::create([
            'source_id' => $reddit->id, 'external_id' => "t3_seed{$i}", 'kind' => 'post',
            'title' => '$ABCD', 'score' => 1, 'num_comments' => 0,
            'posted_at' => now()->subHours(2), 'ingested_at' => now(), 'meta' => [],
        ]);

        PostTickerMention::create([
            'raw_post_id' => $post->id, 'ticker_id' => $ticker->id,
            'method' => 'cashtag', 'confidence' => 1.0, 'posted_at' => now()->subHours(2),
        ]);
    }
});

it('searches trending cashtags and ingests tweets', function () {
    Bus::fake([ProcessRawPost::class]);
    Event::fake([FeedUpdated::class]);

    Http::fake([
        'api.apify.com/v2/acts/*/runs*' => Http::response([
            'data' => ['id' => 'run-1', 'defaultDatasetId' => 'ds-1', 'status' => 'SUCCEEDED'],
        ], 201),
        'api.apify.com/v2/datasets/ds-1/items*' => Http::response([
            [
                'type' => 'tweet',
                'id' => '1728108619189874825',
                'url' => 'https://x.com/trader/status/1728108619189874825',
                'text' => '$ABCD setting up for a squeeze, volume is insane',
                'likeCount' => 55,
                'replyCount' => 12,
                'retweetCount' => 8,
                'createdAt' => 'Fri Nov 24 17:49:36 +0000 2023',
                'lang' => 'en',
                'author' => [
                    'type' => 'user',
                    'userName' => 'pennyflipper',
                    'followers' => 12000,
                    'isBlueVerified' => true,
                ],
            ],
            [
                // Below the min_likes floor — must be skipped.
                'type' => 'tweet',
                'id' => '1728108619189874826',
                'url' => 'https://x.com/bot/status/1728108619189874826',
                'text' => '$ABCD to the moon',
                'likeCount' => 1,
                'createdAt' => 'Fri Nov 24 17:50:00 +0000 2023',
                'author' => ['type' => 'user', 'userName' => 'botfarm1'],
            ],
            [
                // Crypto airdrop colliding with the cashtag — must be skipped.
                'type' => 'tweet',
                'id' => '1728108619189874827',
                'url' => 'https://x.com/scam/status/1728108619189874827',
                'text' => '$ABCD airdrop live! Connect wallet to claim your tokens',
                'likeCount' => 900,
                'createdAt' => 'Fri Nov 24 17:51:00 +0000 2023',
                'author' => ['type' => 'user', 'userName' => 'airdropper'],
            ],
        ]),
    ]);

    runTwitterJob();

    // The search input targeted the trending cashtag with quality filters.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/acts/')) {
            return true;
        }

        return str_contains(json_encode($request->data()), '$ABCD')
            && str_contains(json_encode($request->data()), '-filter:retweets')
            && str_contains(json_encode($request->data()), 'min_faves:5');
    });

    // Low-like and crypto-spam tweets were dropped at ingestion.
    expect(RawPost::query()->where('external_id', '1728108619189874826')->exists())->toBeFalse()
        ->and(RawPost::query()->where('external_id', '1728108619189874827')->exists())->toBeFalse();

    $tweet = RawPost::query()->where('external_id', '1728108619189874825')->first();

    expect($tweet)->not->toBeNull()
        ->and($tweet->source_id)->toBe($this->source->id)
        ->and($tweet->body)->toContain('$ABCD')
        ->and($tweet->score)->toBe(55)
        ->and($tweet->author->platform)->toBe('twitter')
        ->and($tweet->author->stats['followers'])->toBe(12000);

    expect(Author::where('platform', 'twitter')->count())->toBe(1);

    $this->source->refresh();
    expect($this->source->last_ok_at)->not->toBeNull();

    Bus::assertDispatched(ProcessRawPost::class);
    Event::assertDispatched(FeedUpdated::class);
});

it('skips the run when nothing is trending', function () {
    PostTickerMention::query()->delete();
    Http::fake();

    runTwitterJob();

    Http::assertNothingSent();
    expect($this->source->refresh()->last_polled_at)->not->toBeNull();
});

it('does nothing when the twitter integration is disabled', function () {
    config()->set('pennyhunt.apify.twitter.enabled', false);
    Http::fake();

    runTwitterJob();

    Http::assertNothingSent();
});
