<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Batch-level ping fired once per source poll that ingested new posts.
 * The Feed UI listens and refetches (debounced) rather than receiving
 * every post over the wire.
 */
class FeedUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public string $sourceKey,
        public int $ingested,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('pennyhunt.feed');
    }

    public function broadcastAs(): string
    {
        return 'feed.updated';
    }
}
