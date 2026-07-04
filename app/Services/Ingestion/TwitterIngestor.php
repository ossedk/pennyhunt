<?php

namespace App\Services\Ingestion;

use App\Jobs\Pipeline\ProcessRawPost;
use App\Models\Author;
use App\Models\RawPost;
use App\Models\Source;
use App\Services\Nlp\TweetSpamScanner;
use Carbon\CarbonImmutable;

/**
 * Normalizes apidojo/twitter-scraper-lite dataset items into raw_posts +
 * authors, deduplicating on (source, external_id). The downstream pipeline
 * (ticker extraction on cashtags, sentiment) is source-agnostic and runs
 * unchanged.
 *
 * Two quality gates run at ingestion:
 *  - like floor (config min_likes): zero-engagement bot noise is dropped
 *    even if the search-side min_faves filter let it through
 *  - TweetSpamScanner: crypto symbol-collision spam (airdrops, wallet
 *    funnels) and cashtag-stuffing are dropped before they can create
 *    ticker mentions
 */
class TwitterIngestor
{
    public function __construct(protected TweetSpamScanner $spamScanner) {}

    /**
     * @param  array<int, array<string, mixed>>  $tweets  raw actor dataset items
     * @return int number of newly ingested rows
     */
    public function ingest(Source $source, array $tweets): int
    {
        $ingested = 0;
        $minLikes = (int) config('pennyhunt.apify.twitter.min_likes');

        foreach ($tweets as $tweet) {
            $externalId = $tweet['id'] ?? null;

            if ($externalId === null || ($tweet['type'] ?? 'tweet') !== 'tweet') {
                continue;
            }

            if ((int) ($tweet['likeCount'] ?? 0) < $minLikes) {
                continue;
            }

            if ($this->spamScanner->scan((string) ($tweet['text'] ?? '')) !== null) {
                continue;
            }

            $exists = RawPost::query()
                ->where('source_id', $source->id)
                ->where('external_id', $externalId)
                ->exists();

            if ($exists) {
                continue;
            }

            $author = $this->resolveAuthor($tweet['author'] ?? null);

            $post = RawPost::create([
                'source_id' => $source->id,
                'external_id' => $externalId,
                'kind' => 'post',
                'author_id' => $author?->id,
                'title' => null,
                'body' => $tweet['text'] ?? null,
                'permalink' => $tweet['url'] ?? null,
                'score' => (int) ($tweet['likeCount'] ?? 0),
                'num_comments' => (int) ($tweet['replyCount'] ?? 0),
                'posted_at' => isset($tweet['createdAt'])
                    ? CarbonImmutable::parse($tweet['createdAt'])
                    : now(),
                'ingested_at' => now(),
                'meta' => [
                    'retweets' => $tweet['retweetCount'] ?? null,
                    'quotes' => $tweet['quoteCount'] ?? null,
                    'is_retweet' => $tweet['isRetweet'] ?? null,
                    'is_reply' => $tweet['isReply'] ?? null,
                    'lang' => $tweet['lang'] ?? null,
                ],
            ]);

            ProcessRawPost::dispatch($post->id)->onQueue('pipeline');

            $ingested++;
        }

        return $ingested;
    }

    /** @param array<string, mixed>|null $authorData */
    protected function resolveAuthor(?array $authorData): ?Author
    {
        $username = $authorData['userName'] ?? null;

        if ($username === null) {
            return null;
        }

        $author = Author::firstOrCreate(
            ['platform' => 'twitter', 'username' => $username],
            ['stats' => []],
        );

        // Follower count and verification feed author-quality weighting.
        $author->update([
            'stats' => [
                ...($author->stats ?? []),
                'followers' => $authorData['followers'] ?? null,
                'is_verified' => $authorData['isBlueVerified'] ?? $authorData['isVerified'] ?? null,
            ],
        ]);

        return $author;
    }
}
