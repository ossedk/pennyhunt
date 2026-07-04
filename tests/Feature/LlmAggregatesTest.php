<?php

use App\Models\Author;
use App\Models\PostSentiment;
use App\Models\PostTickerMention;
use App\Models\RawPost;
use App\Models\Source;
use App\Models\Ticker;
use App\Services\Features\LlmAggregates;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function aggPost(Source $source, Author $author, Ticker $ticker, string $postedAt, ?array $llm): RawPost
{
    static $n = 0;

    $post = RawPost::create([
        'source_id' => $source->id, 'external_id' => 't3_agg'.(++$n), 'kind' => 'post',
        'author_id' => $author->id, 'title' => '$'.$ticker->symbol.' post '.$n, 'body' => 'body',
        'score' => 5, 'num_comments' => 0, 'posted_at' => $postedAt, 'ingested_at' => now(), 'meta' => [],
    ]);

    PostTickerMention::create([
        'raw_post_id' => $post->id, 'ticker_id' => $ticker->id,
        'confidence' => 1.0, 'method' => 'cashtag', 'posted_at' => $postedAt,
    ]);

    if ($llm !== null) {
        PostSentiment::create(['raw_post_id' => $post->id, ...$llm]);
    }

    return $post;
}

it('aggregates classified posts per ticker-day and reports coverage', function () {
    $source = Source::create([
        'key' => 'reddit:agg', 'type' => 'reddit', 'name' => 'r/agg',
        'enabled' => true, 'poll_interval_seconds' => 120, 'config' => [],
    ]);
    $author = Author::create(['platform' => 'reddit', 'username' => 'agg', 'stats' => []]);
    $ticker = Ticker::create(['symbol' => 'AGGT', 'name' => 'Agg Test', 'is_active' => true]);

    // Day with 4 mentions: 3 classified (dd bullish conv .8 catalyst, hype
    // bullish conv .4, news bearish conv .6), 1 unclassified.
    aggPost($source, $author, $ticker, '2026-06-01 10:00:00', [
        'llm_post_type' => 'dd', 'llm_direction' => 'bullish', 'llm_conviction' => 0.8,
        'llm_pump_suspicion' => 0.1, 'llm_catalyst' => true,
    ]);
    aggPost($source, $author, $ticker, '2026-06-01 11:00:00', [
        'llm_post_type' => 'hype', 'llm_direction' => 'bullish', 'llm_conviction' => 0.4,
        'llm_pump_suspicion' => 0.7, 'llm_catalyst' => false,
    ]);
    aggPost($source, $author, $ticker, '2026-06-01 12:00:00', [
        'llm_post_type' => 'news', 'llm_direction' => 'bearish', 'llm_conviction' => 0.6,
        'llm_pump_suspicion' => 0.0, 'llm_catalyst' => false,
    ]);
    aggPost($source, $author, $ticker, '2026-06-01 13:00:00', null);

    $llm = LlmAggregates::load([$ticker->id], '2026-06-01', '2026-06-01');
    $features = $llm->features($ticker->id, '2026-06-01');

    expect($features['llm_coverage'])->toEqualWithDelta(0.75, 0.001)
        ->and($features['llm_direction'])->toEqualWithDelta((1 + 1 - 1) / 3, 0.001)
        ->and($features['llm_conviction'])->toEqualWithDelta(0.6, 0.001)
        ->and($features['llm_pump_suspicion'])->toEqualWithDelta(0.2667, 0.001)
        ->and($features['llm_dd_share'])->toEqualWithDelta(1 / 3, 0.001)
        ->and($features['llm_hype_share'])->toEqualWithDelta(1 / 3, 0.001)
        ->and($features['llm_news_share'])->toEqualWithDelta(1 / 3, 0.001)
        ->and($features['llm_catalyst_share'])->toEqualWithDelta(1 / 3, 0.001);
});

it('returns the neutral zero vector for days without classified posts', function () {
    $source = Source::create([
        'key' => 'reddit:agg2', 'type' => 'reddit', 'name' => 'r/agg2',
        'enabled' => true, 'poll_interval_seconds' => 120, 'config' => [],
    ]);
    $author = Author::create(['platform' => 'reddit', 'username' => 'agg2', 'stats' => []]);
    $ticker = Ticker::create(['symbol' => 'NLLM', 'name' => 'No Llm', 'is_active' => true]);

    aggPost($source, $author, $ticker, '2026-06-02 10:00:00', null);

    $llm = LlmAggregates::load([$ticker->id], '2026-06-01', '2026-06-03');

    // Unclassified-only day AND a fully silent day both read as zeros.
    expect($llm->features($ticker->id, '2026-06-02'))->toBe(array_fill_keys(LlmAggregates::FEATURE_KEYS, 0.0))
        ->and($llm->features($ticker->id, '2026-06-03'))->toBe(array_fill_keys(LlmAggregates::FEATURE_KEYS, 0.0));
});

it('does not leak posts across day or ticker boundaries', function () {
    $source = Source::create([
        'key' => 'reddit:agg3', 'type' => 'reddit', 'name' => 'r/agg3',
        'enabled' => true, 'poll_interval_seconds' => 120, 'config' => [],
    ]);
    $author = Author::create(['platform' => 'reddit', 'username' => 'agg3', 'stats' => []]);
    $tickerA = Ticker::create(['symbol' => 'AAAA', 'name' => 'A', 'is_active' => true]);
    $tickerB = Ticker::create(['symbol' => 'BBBB', 'name' => 'B', 'is_active' => true]);

    aggPost($source, $author, $tickerA, '2026-06-01 10:00:00', [
        'llm_post_type' => 'dd', 'llm_direction' => 'bullish', 'llm_conviction' => 1.0,
        'llm_pump_suspicion' => 0.0, 'llm_catalyst' => true,
    ]);
    aggPost($source, $author, $tickerB, '2026-06-02 10:00:00', [
        'llm_post_type' => 'hype', 'llm_direction' => 'bearish', 'llm_conviction' => 0.5,
        'llm_pump_suspicion' => 1.0, 'llm_catalyst' => false,
    ]);

    $llm = LlmAggregates::load([$tickerA->id, $tickerB->id], '2026-06-01', '2026-06-02');

    expect($llm->features($tickerA->id, '2026-06-01')['llm_dd_share'])->toEqual(1.0)
        ->and($llm->features($tickerA->id, '2026-06-02'))->toBe(array_fill_keys(LlmAggregates::FEATURE_KEYS, 0.0))
        ->and($llm->features($tickerB->id, '2026-06-02')['llm_hype_share'])->toEqual(1.0)
        ->and($llm->features($tickerB->id, '2026-06-02')['llm_direction'])->toEqual(-1.0);
});
