<?php

namespace App\Http\Controllers;

use App\Models\RawPost;
use App\Models\Source;
use App\Services\Ingestion\RedditClient;
use Inertia\Inertia;
use Inertia\Response;

class SourcesController extends Controller
{
    public function index(RedditClient $redditClient): Response
    {
        $counts = RawPost::query()
            ->selectRaw('source_id, COUNT(*) AS total, MAX(posted_at) AS latest_post_at')
            ->groupBy('source_id')
            ->get()
            ->keyBy('source_id');

        $sources = Source::query()
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn (Source $source): array => [
                'id' => $source->id,
                'key' => $source->key,
                'type' => $source->type,
                'name' => $source->name,
                'enabled' => $source->enabled,
                'poll_interval_seconds' => $source->poll_interval_seconds,
                'last_polled_at' => $source->last_polled_at?->toIso8601String(),
                'last_ok_at' => $source->last_ok_at?->toIso8601String(),
                'last_error' => $source->last_error,
                'consecutive_failures' => $source->consecutive_failures,
                'total_posts' => (int) ($counts[$source->id]->total ?? 0),
                'latest_post_at' => $counts[$source->id]->latest_post_at ?? null,
            ]);

        return Inertia::render('sources', [
            'sources' => $sources,
            // Apify is the primary Reddit path; native OAuth is the fallback.
            'redditConfigured' => filled(config('pennyhunt.apify.token')) || $redditClient->isConfigured(),
        ]);
    }
}
