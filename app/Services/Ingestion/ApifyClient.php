<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Minimal Apify REST client: start an actor run asynchronously, poll until it
 * finishes, then fetch the dataset items. We avoid Apify's run-sync endpoints
 * because a batched multi-subreddit scrape can exceed their 300s HTTP limit.
 */
class ApifyClient
{
    protected const BASE_URL = 'https://api.apify.com/v2';

    public function isConfigured(): bool
    {
        return filled(config('pennyhunt.apify.token'));
    }

    /**
     * Run an actor to completion and return its dataset items.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, array<string, mixed>>
     */
    public function runActor(string $actorId, array $input, int $maxWaitSeconds = 540): array
    {
        $token = config('pennyhunt.apify.token');

        $run = Http::retry(3, 5000)
            ->timeout(30)
            ->post(self::BASE_URL."/acts/{$actorId}/runs?token={$token}", $input)
            ->throw()
            ->json('data');

        $runId = $run['id'];
        $datasetId = $run['defaultDatasetId'];

        $deadline = now()->addSeconds($maxWaitSeconds);
        $status = $run['status'] ?? 'READY';

        while (in_array($status, ['READY', 'RUNNING'], true)) {
            if (now()->greaterThan($deadline)) {
                // Best effort: stop the run so it doesn't keep accruing results.
                Http::timeout(30)->post(self::BASE_URL."/actor-runs/{$runId}/abort?token={$token}");

                throw new RuntimeException("Apify run {$runId} exceeded {$maxWaitSeconds}s and was aborted.");
            }

            Sleep::for(10)->seconds();

            // Transient network failures (DNS, timeouts) must not kill the
            // poll loop while the paid run keeps going on Apify's side.
            $status = Http::retry(3, 3000, throw: false)
                ->timeout(30)
                ->get(self::BASE_URL."/actor-runs/{$runId}?token={$token}")
                ->json('data.status') ?? $status;
        }

        if ($status !== 'SUCCEEDED') {
            throw new RuntimeException("Apify run {$runId} finished with status {$status}.");
        }

        return Http::retry(3, 5000)
            ->timeout(120)
            ->get(self::BASE_URL."/datasets/{$datasetId}/items?token={$token}&clean=true&format=json")
            ->throw()
            ->json() ?? [];
    }
}
