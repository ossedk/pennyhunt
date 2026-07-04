<?php

namespace App\Jobs\Ingestion;

use App\Events\FeedUpdated;
use App\Models\Source;
use App\Services\Ingestion\ApifyClient;
use App\Services\Ingestion\RedditIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Primary Reddit ingestion path: one batched Apify "Reddit Scraper Lite" run
 * scrapes /new/ for every enabled subreddit source. postDateLimit restricts
 * results (and pay-per-result billing) to posts newer than the last successful
 * poll; the (source_id, external_id) dedupe absorbs the safety overlap.
 */
class PollRedditViaApify implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /** A slow actor run must not overlap with the next scheduled poll. */
    public int $uniqueFor = 900;

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(ApifyClient $client, RedditIngestor $ingestor): void
    {
        if (! $client->isConfigured()) {
            return;
        }

        /** @var Collection<string, Source> $sources keyed by lowercase subreddit */
        $sources = Source::query()
            ->where('type', 'reddit')
            ->where('enabled', true)
            ->get()
            ->keyBy(fn (Source $source) => strtolower($source->config['subreddit']));

        if ($sources->isEmpty()) {
            return;
        }

        try {
            $items = $client->runActor(
                config('pennyhunt.apify.reddit_actor'),
                $this->buildInput($sources),
                maxWaitSeconds: 780,
            );
        } catch (Throwable $e) {
            $sources->each(fn (Source $source) => $source->markFailed($e->getMessage()));

            throw $e;
        }

        $bySubreddit = collect($items)
            ->filter(fn (array $item) => in_array($item['dataType'] ?? null, ['post', 'comment'], true))
            ->groupBy(fn (array $item) => strtolower($item['parsedCommunityName'] ?? ''));

        foreach ($sources as $subreddit => $source) {
            $subredditItems = $bySubreddit->get($subreddit, collect());

            $ingested = 0;

            foreach (['post', 'comment'] as $kind) {
                $things = $subredditItems
                    ->filter(fn (array $item) => $item['dataType'] === $kind)
                    ->map(fn (array $item) => $this->toRedditThing($item))
                    ->values()
                    ->all();

                $ingested += $ingestor->ingest($source, $things, $kind);
            }

            if ($ingested > 0) {
                FeedUpdated::dispatch($source->key, $ingested);
            }

            $source->markPolled();
        }
    }

    /**
     * @param  Collection<string, Source>  $sources
     * @return array<string, mixed>
     */
    protected function buildInput(Collection $sources): array
    {
        $maxPosts = (int) config('pennyhunt.apify.max_posts_per_subreddit', 15);
        $includeComments = (bool) config('pennyhunt.apify.include_comments', false);

        return [
            'startUrls' => $sources
                ->map(fn (Source $source) => ['url' => 'https://www.reddit.com/r/'.$source->config['subreddit'].'/new/'])
                ->values()
                ->all(),
            'skipComments' => ! $includeComments,
            'skipCommunity' => true,
            'skipUserPosts' => true,
            // Detailed extraction (upVotes etc.) renders every post page in a
            // browser at ~30-45s each; fast RSS mode is the only viable option
            // at this subreddit count.
            'includeMediaLinks' => (bool) config('pennyhunt.apify.include_media_links', false),
            'maxItems' => $sources->count() * $maxPosts * ($includeComments ? 6 : 1),
            'maxPostCount' => $maxPosts,
            'maxComments' => $includeComments ? 25 : 0,
            'postDateLimit' => $this->sinceTimestamp($sources)->toIso8601ZuluString(),
            'sort' => 'new',
            'scrollTimeout' => (int) config('pennyhunt.apify.scroll_timeout', 10),
            'proxy' => ['useApifyProxy' => true],
        ];
    }

    /**
     * Only pay for posts newer than the last successful poll (minus a dedupe
     * overlap). A source that has never succeeded gets a bounded backfill.
     *
     * @param  Collection<string, Source>  $sources
     */
    protected function sinceTimestamp(Collection $sources): CarbonImmutable
    {
        $backfillHours = (int) config('pennyhunt.apify.first_run_backfill_hours', 24);
        $floor = CarbonImmutable::now()->subHours($backfillHours);

        $oldestOk = $sources
            ->map(fn (Source $source) => $source->last_ok_at)
            ->filter()
            ->min();

        if ($oldestOk === null || $sources->contains(fn (Source $source) => $source->last_ok_at === null)) {
            return $floor;
        }

        return CarbonImmutable::parse($oldestOk)->subMinutes(10)->max($floor);
    }

    /**
     * Map an Apify dataset item onto the raw Reddit "thing" shape that
     * RedditIngestor already understands, so both ingestion paths share
     * normalization and dedupe.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function toRedditThing(array $item): array
    {
        $permalink = isset($item['url']) ? parse_url($item['url'], PHP_URL_PATH) : null;

        return [
            'name' => $item['id'],
            'title' => $item['title'] ?? null,
            'selftext' => $item['body'] ?? null,
            'body' => $item['body'] ?? null,
            'permalink' => $permalink,
            'score' => (int) ($item['upVotes'] ?? 0),
            'num_comments' => (int) ($item['numberOfComments'] ?? 0),
            'created_utc' => isset($item['createdAt'])
                ? CarbonImmutable::parse($item['createdAt'])->timestamp
                : now()->timestamp,
            'author' => $item['username'] ?? null,
            'subreddit' => $item['parsedCommunityName'] ?? null,
            'upvote_ratio' => $item['upVoteRatio'] ?? null,
            'link_flair_text' => null,
        ];
    }
}
