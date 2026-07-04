<?php

namespace App\Services\Ingestion;

use App\Jobs\Pipeline\ProcessRawPost;
use App\Models\Author;
use App\Models\RawPost;
use App\Models\Source;
use Carbon\CarbonImmutable;

/**
 * Normalizes raw Reddit API listings into raw_posts + authors,
 * deduplicating on (source, external_id).
 */
class RedditIngestor
{
    /**
     * @param  array<int, array<string, mixed>>  $things  raw reddit thing data
     * @return int number of newly ingested rows
     */
    public function ingest(Source $source, array $things, string $kind): int
    {
        $ingested = 0;

        foreach ($things as $thing) {
            $externalId = $thing['name'] ?? null; // fullname, e.g. t3_abc123

            if ($externalId === null) {
                continue;
            }

            $exists = RawPost::query()
                ->where('source_id', $source->id)
                ->where('external_id', $externalId)
                ->exists();

            if ($exists) {
                continue;
            }

            $author = $this->resolveAuthor($thing);

            $post = RawPost::create([
                'source_id' => $source->id,
                'external_id' => $externalId,
                'kind' => $kind,
                'author_id' => $author?->id,
                'title' => $thing['title'] ?? null,
                'body' => $kind === 'comment' ? ($thing['body'] ?? null) : ($thing['selftext'] ?? null),
                'permalink' => isset($thing['permalink']) ? 'https://reddit.com'.$thing['permalink'] : null,
                'score' => (int) ($thing['score'] ?? 0),
                'num_comments' => (int) ($thing['num_comments'] ?? 0),
                'posted_at' => CarbonImmutable::createFromTimestampUTC((int) ($thing['created_utc'] ?? now()->timestamp)),
                'ingested_at' => now(),
                'meta' => [
                    'subreddit' => $thing['subreddit'] ?? null,
                    'upvote_ratio' => $thing['upvote_ratio'] ?? null,
                    'link_flair_text' => $thing['link_flair_text'] ?? null,
                ],
            ]);

            ProcessRawPost::dispatch($post->id)->onQueue('pipeline');

            $ingested++;
        }

        return $ingested;
    }

    protected function resolveAuthor(array $thing): ?Author
    {
        $username = $thing['author'] ?? null;

        if ($username === null || $username === '[deleted]') {
            return null;
        }

        return Author::firstOrCreate(
            ['platform' => 'reddit', 'username' => $username],
            ['stats' => []],
        );
    }
}
