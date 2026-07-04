<?php

namespace App\Jobs\Ingestion;

use App\Models\Source;
use App\Services\Ingestion\RedditClient;
use App\Services\Ingestion\RedditIngestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PollRedditSubreddit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $sourceId)
    {
        $this->onQueue('ingestion');
    }

    public function handle(RedditClient $client, RedditIngestor $ingestor): void
    {
        $source = Source::findOrFail($this->sourceId);

        if (! $source->enabled || ! $client->isConfigured()) {
            return;
        }

        $subreddit = $source->config['subreddit'];

        try {
            $posts = $client->newPosts($subreddit);
            $comments = $client->newComments($subreddit);

            $ingested = $ingestor->ingest($source, $posts, 'post')
                + $ingestor->ingest($source, $comments, 'comment');

            if ($ingested > 0) {
                \App\Events\FeedUpdated::dispatch($source->key, $ingested);
            }

            $source->markPolled();
        } catch (Throwable $e) {
            $source->markFailed($e->getMessage());

            throw $e;
        }
    }
}
