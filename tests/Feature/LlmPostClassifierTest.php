<?php

use App\Models\Author;
use App\Models\PostSentiment;
use App\Models\RawPost;
use App\Models\Source;
use App\Services\Nlp\LlmPostClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function llmPost(): RawPost
{
    $source = Source::create([
        'key' => 'reddit:test', 'type' => 'reddit', 'name' => 'r/test',
        'enabled' => true, 'poll_interval_seconds' => 120, 'config' => [],
    ]);
    $author = Author::create(['platform' => 'reddit', 'username' => 'dd_writer', 'stats' => []]);

    return RawPost::create([
        'source_id' => $source->id, 'external_id' => 't3_llm', 'kind' => 'post',
        'author_id' => $author->id, 'title' => '$ABCD deep dive',
        'body' => 'Revenue tripled, FDA decision on Aug 4, still trading below cash.',
        'score' => 10, 'num_comments' => 3, 'posted_at' => now(), 'ingested_at' => now(),
        'meta' => [],
    ]);
}

it('classifies a post via anthropic and persists structured fields', function () {
    config(['pennyhunt.llm.openai_api_key' => null, 'pennyhunt.llm.anthropic_api_key' => 'test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'post_type' => 'dd', 'direction' => 0.8, 'conviction' => 0.7,
                    'pump_suspicion' => 0.1, 'catalyst' => true, 'reasoning' => 'FDA date claimed.',
                ]),
            ]],
        ]),
    ]);

    $stored = app(LlmPostClassifier::class)->classifyAndStore(llmPost());

    expect($stored)->toBeTrue();

    $sentiment = PostSentiment::first();

    expect($sentiment->llm_post_type)->toBe('dd')
        ->and($sentiment->llm_direction)->toBe('bullish')
        ->and($sentiment->llm_conviction)->toEqual(0.7)
        ->and($sentiment->llm_pump_suspicion)->toEqual(0.1)
        ->and($sentiment->llm_catalyst)->toBeTrue();
});

it('tolerates code fences and clamps out-of-range values', function () {
    config(['pennyhunt.llm.openai_api_key' => null, 'pennyhunt.llm.anthropic_api_key' => 'test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => "```json\n".json_encode([
                    'post_type' => 'weird_type', 'direction' => -3.0, 'conviction' => 1.7,
                    'pump_suspicion' => 0.9, 'catalyst' => false, 'reasoning' => 'x',
                ])."\n```",
            ]],
        ]),
    ]);

    app(LlmPostClassifier::class)->classifyAndStore(llmPost());

    $sentiment = PostSentiment::first();

    expect($sentiment->llm_post_type)->toBe('other')
        ->and($sentiment->llm_direction)->toBe('bearish')
        ->and($sentiment->llm_conviction)->toEqual(1.0);
});

it('is disabled without an API key', function () {
    config(['pennyhunt.llm.openai_api_key' => null, 'pennyhunt.llm.anthropic_api_key' => null]);

    Http::fake();

    expect(app(LlmPostClassifier::class)->enabled())->toBeFalse()
        ->and(app(LlmPostClassifier::class)->classify('some text'))->toBeNull();

    Http::assertNothingSent();
});

it('prefers openai when both keys are configured', function () {
    config([
        'pennyhunt.llm.openai_api_key' => 'sk-test',
        'pennyhunt.llm.anthropic_api_key' => 'also-set',
        'pennyhunt.llm.openai_model' => 'gpt-5-mini',
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'post_type' => 'hype', 'direction' => 0.9, 'conviction' => 0.4,
                        'pump_suspicion' => 0.8, 'catalyst' => false, 'reasoning' => 'Rockets only.',
                    ]),
                ],
            ]],
        ]),
    ]);

    $stored = app(LlmPostClassifier::class)->classifyAndStore(llmPost());

    expect($stored)->toBeTrue();

    $sentiment = PostSentiment::first();

    expect($sentiment->llm_post_type)->toBe('hype')
        ->and($sentiment->llm_direction)->toBe('bullish')
        ->and($sentiment->llm_pump_suspicion)->toEqual(0.8);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com')
        && $request['model'] === 'gpt-5-mini'
        && $request['response_format']['type'] === 'json_object');
});

it('deletes ticker mentions when a tweet is classified off-topic', function () {
    config(['pennyhunt.llm.openai_api_key' => 'sk-test', 'pennyhunt.llm.openai_model' => 'gpt-5-mini']);

    $source = Source::create([
        'key' => 'twitter:cashtags', 'type' => 'twitter', 'name' => 'X',
        'enabled' => true, 'poll_interval_seconds' => 3600, 'config' => [],
    ]);
    $ticker = App\Models\Ticker::create(['symbol' => 'ABCD', 'name' => 'Abcd Corp', 'is_active' => true]);
    $tweet = RawPost::create([
        'source_id' => $source->id, 'external_id' => 'tw_offtopic', 'kind' => 'post',
        'body' => '$ABCD token airdrop distribution starts today for early stakers',
        'score' => 40, 'num_comments' => 2, 'posted_at' => now(), 'ingested_at' => now(), 'meta' => [],
    ]);
    App\Models\PostTickerMention::create([
        'raw_post_id' => $tweet->id, 'ticker_id' => $ticker->id,
        'method' => 'cashtag', 'confidence' => 1.0, 'posted_at' => now(),
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'post_type' => 'other', 'direction' => 0.0, 'conviction' => 0.0,
                        'pump_suspicion' => 0.9, 'catalyst' => false, 'off_topic' => true,
                        'reasoning' => 'Crypto airdrop, not the equity.',
                    ]),
                ],
            ]],
        ]),
    ]);

    app(LlmPostClassifier::class)->classifyAndStore($tweet);

    expect(PostSentiment::first()->llm_off_topic)->toBeTrue()
        ->and(App\Models\PostTickerMention::count())->toBe(0);
});
