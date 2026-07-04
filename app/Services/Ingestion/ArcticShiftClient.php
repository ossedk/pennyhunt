<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Client for the Arctic Shift archive (Pushshift successor) — free historical
 * Reddit data used for backtest backfill. Public API, no auth, max 100 items
 * per page; we paginate on created_utc and stay polite at ~1 req/sec.
 *
 * https://github.com/ArthurHeitmann/arctic_shift
 */
class ArcticShiftClient
{
    protected const BASE_URL = 'https://arctic-shift.photon-reddit.com/api';

    protected const FIELDS = 'id,author,title,selftext,created_utc,score,num_comments,subreddit,link_flair_text';

    /**
     * Stream historical posts for a subreddit, oldest first.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function posts(string $subreddit, int $afterEpoch, int $beforeEpoch): \Generator
    {
        $cursor = $afterEpoch;

        while ($cursor < $beforeEpoch) {
            $page = $this->fetchPage([
                'subreddit' => $subreddit,
                'after' => $cursor,
                'before' => $beforeEpoch,
                'limit' => 100,
                'sort' => 'asc',
                'fields' => self::FIELDS,
            ]);

            if ($page === []) {
                return;
            }

            yield from $page;

            $last = (int) end($page)['created_utc'];

            // Advance the cursor; the guard guarantees progress even if the
            // whole page shares one timestamp (dedupe makes overlaps safe).
            $cursor = $last > $cursor ? $last : $cursor + 1;

            if (count($page) < 100) {
                return;
            }

            Sleep::for(1)->second();
        }
    }

    /**
     * One page with backoff. Arctic Shift returns 422 "Timeout. Maybe slow
     * down a bit" under load — treated as a retryable rate-limit signal.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    protected function fetchPage(array $query): array
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            $response = Http::retry(3, 5000, throw: false)
                ->timeout(90)
                ->get(self::BASE_URL.'/posts/search', $query);

            if ($response->successful()) {
                return $response->json('data') ?? [];
            }

            if ($attempts >= 6) {
                $response->throw();
            }

            // Exponential backoff: 15s, 30s, 60s, 120s, 240s.
            Sleep::for(min(15 * 2 ** ($attempts - 1), 240))->seconds();
        }
    }
}
