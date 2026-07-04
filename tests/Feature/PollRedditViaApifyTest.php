<?php

use App\Events\FeedUpdated;
use App\Jobs\Ingestion\PollRedditViaApify;
use App\Jobs\Pipeline\ProcessRawPost;
use App\Models\RawPost;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('pennyhunt.apify.token', 'test-token');

    $this->source = Source::create([
        'key' => 'reddit:pennystocks',
        'type' => 'reddit',
        'name' => 'r/pennystocks',
        'enabled' => true,
        'poll_interval_seconds' => 120,
        'config' => ['subreddit' => 'pennystocks'],
    ]);
});

it('ingests posts from a batched apify run', function () {
    Bus::fake([ProcessRawPost::class]);
    Event::fake([FeedUpdated::class]);

    Http::fake([
        'api.apify.com/v2/acts/*/runs*' => Http::response([
            'data' => ['id' => 'run-1', 'defaultDatasetId' => 'ds-1', 'status' => 'SUCCEEDED'],
        ], 201),
        'api.apify.com/v2/datasets/ds-1/items*' => Http::response([
            [
                'id' => 't3_abc123',
                'url' => 'https://www.reddit.com/r/pennystocks/comments/abc123/moon_soon/',
                'username' => 'diamond_hands',
                'title' => '$ABCD to the moon',
                'body' => 'Loaded up on $ABCD today.',
                'upVotes' => 42,
                'upVoteRatio' => 0.95,
                'numberOfComments' => 7,
                'createdAt' => '2026-07-02T12:00:00.000Z',
                'parsedCommunityName' => 'pennystocks',
                'dataType' => 'post',
            ],
            [
                'id' => '2qqoq',
                'dataType' => 'community',
                'parsedCommunityName' => 'pennystocks',
            ],
        ]),
    ]);

    (new PollRedditViaApify)->handle(app(\App\Services\Ingestion\ApifyClient::class), app(\App\Services\Ingestion\RedditIngestor::class));

    $post = RawPost::query()->where('external_id', 't3_abc123')->first();

    expect($post)->not->toBeNull()
        ->and($post->source_id)->toBe($this->source->id)
        ->and($post->title)->toBe('$ABCD to the moon')
        ->and($post->score)->toBe(42)
        ->and($post->posted_at->toIso8601ZuluString())->toBe('2026-07-02T12:00:00Z')
        ->and($post->author->username)->toBe('diamond_hands');

    expect(RawPost::count())->toBe(1); // community item was ignored

    $this->source->refresh();
    expect($this->source->last_ok_at)->not->toBeNull();

    Bus::assertDispatched(ProcessRawPost::class);
    Event::assertDispatched(FeedUpdated::class);
});

it('marks sources failed when the apify run fails', function () {
    Http::fake([
        'api.apify.com/v2/acts/*/runs*' => Http::response([
            'data' => ['id' => 'run-1', 'defaultDatasetId' => 'ds-1', 'status' => 'FAILED'],
        ], 201),
    ]);

    expect(fn () => (new PollRedditViaApify)->handle(app(\App\Services\Ingestion\ApifyClient::class), app(\App\Services\Ingestion\RedditIngestor::class)))
        ->toThrow(RuntimeException::class);

    $this->source->refresh();
    expect($this->source->last_error)->toContain('FAILED')
        ->and($this->source->consecutive_failures)->toBe(1);
});

it('does nothing without an apify token', function () {
    config()->set('pennyhunt.apify.token', null);
    Http::fake();

    (new PollRedditViaApify)->handle(app(\App\Services\Ingestion\ApifyClient::class), app(\App\Services\Ingestion\RedditIngestor::class));

    Http::assertNothingSent();
    expect(RawPost::count())->toBe(0);
});
