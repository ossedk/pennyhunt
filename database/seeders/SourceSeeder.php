<?php

namespace Database\Seeders;

use App\Models\Source;
use Illuminate\Database\Seeder;

class SourceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('pennyhunt.subreddits') as $subreddit) {
            Source::firstOrCreate(
                ['key' => 'reddit:'.strtolower($subreddit)],
                [
                    'type' => 'reddit',
                    'name' => 'r/'.$subreddit,
                    'enabled' => true,
                    'poll_interval_seconds' => 120,
                    'config' => ['subreddit' => $subreddit],
                ],
            );
        }

        Source::firstOrCreate(
            ['key' => 'apewisdom'],
            [
                'type' => 'aggregator',
                'name' => 'ApeWisdom (multi-subreddit mentions)',
                'enabled' => true,
                'poll_interval_seconds' => 1800,
                'config' => [],
            ],
        );

        // X/Twitter cashtag confirmation via Apify (apidojo/twitter-scraper-
        // lite). The scheduler additionally requires PENNYHUNT_TWITTER_ENABLED
        // because the actor needs a paid Apify plan.
        Source::firstOrCreate(
            ['key' => 'twitter:cashtags'],
            [
                'type' => 'twitter',
                'name' => 'X/Twitter (trending cashtags)',
                'enabled' => true,
                'poll_interval_seconds' => 3600,
                'config' => [],
            ],
        );

        // Disabled by default: Tradestie sits behind an active Cloudflare JS
        // challenge (July 2026) that server-side clients cannot pass. Re-enable
        // from the DB if their API becomes reachable again.
        Source::firstOrCreate(
            ['key' => 'tradestie'],
            [
                'type' => 'aggregator',
                'name' => 'Tradestie (WSB top-50 sentiment)',
                'enabled' => false,
                'poll_interval_seconds' => 1800,
                'config' => [],
            ],
        );
    }
}
