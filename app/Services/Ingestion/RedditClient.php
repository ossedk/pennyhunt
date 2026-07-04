<?php

namespace App\Services\Ingestion;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only Reddit Data API client using app-only OAuth (client_credentials).
 * Free tier, non-commercial research use. 100 req/min per OAuth client.
 */
class RedditClient
{
    public function isConfigured(): bool
    {
        return filled(config('pennyhunt.reddit.client_id'))
            && filled(config('pennyhunt.reddit.client_secret'));
    }

    /**
     * @return array<int, array<string, mixed>> raw reddit "thing" data arrays
     */
    public function newPosts(string $subreddit, int $limit = 100): array
    {
        $response = $this->request()
            ->get("https://oauth.reddit.com/r/{$subreddit}/new", [
                'limit' => $limit,
                'raw_json' => 1,
            ])
            ->throw()
            ->json();

        return collect($response['data']['children'] ?? [])
            ->pluck('data')
            ->all();
    }

    /**
     * Recent comments across a subreddit (r/{sub}/comments listing).
     *
     * @return array<int, array<string, mixed>>
     */
    public function newComments(string $subreddit, int $limit = 100): array
    {
        $response = $this->request()
            ->get("https://oauth.reddit.com/r/{$subreddit}/comments", [
                'limit' => $limit,
                'raw_json' => 1,
            ])
            ->throw()
            ->json();

        return collect($response['data']['children'] ?? [])
            ->pluck('data')
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function aboutUser(string $username): ?array
    {
        $response = $this->request()
            ->get("https://oauth.reddit.com/user/{$username}/about", ['raw_json' => 1]);

        if ($response->failed()) {
            return null;
        }

        return $response->json('data');
    }

    protected function request(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->withHeaders(['User-Agent' => config('pennyhunt.reddit.user_agent')])
            ->timeout(20);
    }

    protected function accessToken(): string
    {
        return Cache::remember('reddit:app_token', now()->addMinutes(50), function (): string {
            $response = Http::asForm()
                ->withBasicAuth(
                    config('pennyhunt.reddit.client_id'),
                    config('pennyhunt.reddit.client_secret'),
                )
                ->withHeaders(['User-Agent' => config('pennyhunt.reddit.user_agent')])
                ->post('https://www.reddit.com/api/v1/access_token', [
                    'grant_type' => 'client_credentials',
                ]);

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Reddit OAuth token request failed: '.$response->body());
            }

            return $token;
        });
    }
}
