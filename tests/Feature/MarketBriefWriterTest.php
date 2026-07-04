<?php

use App\Models\Author;
use App\Models\MarketBrief;
use App\Models\PostTickerMention;
use App\Models\RawPost;
use App\Models\Source;
use App\Models\Ticker;
use App\Services\Nlp\MarketBriefWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function briefMention(string $symbol): void
{
    $ticker = Ticker::firstOrCreate(['symbol' => $symbol], ['name' => $symbol.' Corp', 'is_active' => true]);
    $source = Source::firstOrCreate(
        ['key' => 'reddit:brief'],
        ['type' => 'reddit', 'name' => 'r/brief', 'enabled' => true, 'poll_interval_seconds' => 120, 'config' => []],
    );
    $author = Author::firstOrCreate(['platform' => 'reddit', 'username' => 'briefer'], ['stats' => []]);

    $post = RawPost::create([
        'source_id' => $source->id,
        'author_id' => $author->id,
        'external_id' => 'brief-'.$symbol.'-'.uniqid(),
        'kind' => 'post',
        'title' => 'to the moon',
        'body' => 'up only',
        'posted_at' => now()->subHours(1),
        'ingested_at' => now(),
        'meta' => [],
    ]);

    PostTickerMention::create([
        'raw_post_id' => $post->id,
        'ticker_id' => $ticker->id,
        'method' => 'cashtag',
        'confidence' => 1,
        'posted_at' => now()->subHours(1),
    ]);
}

function fakeBriefCompletion(array $brief): void
{
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($brief)]]],
        ]),
        'api.polygon.io/*' => Http::response([], 500),
    ]);
}

beforeEach(function () {
    config([
        'pennyhunt.llm.openai_api_key' => 'test-key',
        'pennyhunt.llm.openai_model' => 'gpt-5-mini',
    ]);
    Cache::flush();
});

it('stores a validated brief and enforces the closed world on watch items', function () {
    briefMention('REAL');

    fakeBriefCompletion([
        'headline' => 'Quiet tape, loud forums',
        'body' => ['Regime is calm.', 'REAL is the only loud name.'],
        'watch' => [
            ['symbol' => 'real', 'reason' => 'Mention spike without price confirmation'],
            ['symbol' => 'FAKE', 'reason' => 'Hallucinated ticker must be dropped'],
        ],
        'risks' => ['Thin data overnight'],
    ]);

    $brief = app(MarketBriefWriter::class)->write();

    expect($brief)->not->toBeNull()
        ->and($brief->brief['headline'])->toBe('Quiet tape, loud forums')
        ->and($brief->brief['watch'])->toHaveCount(1)
        ->and($brief->brief['watch'][0]['symbol'])->toBe('REAL')
        ->and($brief->context['loudest_tickers_24h'][0]['symbol'])->toBe('REAL');

    expect(MarketBrief::count())->toBe(1);
});

it('returns null and stores nothing when the LLM output is unusable', function () {
    fakeBriefCompletion(['garbage' => true]);

    expect(app(MarketBriefWriter::class)->write())->toBeNull()
        ->and(MarketBrief::count())->toBe(0);
});

it('is disabled without an api key', function () {
    config(['pennyhunt.llm.openai_api_key' => null]);

    expect(app(MarketBriefWriter::class)->write())->toBeNull();
});
