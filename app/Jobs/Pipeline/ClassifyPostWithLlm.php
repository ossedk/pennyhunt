<?php

namespace App\Jobs\Pipeline;

use App\Models\RawPost;
use App\Services\Nlp\LlmPostClassifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Tier-2 sentiment: LLM classification of a ticker-mentioning post.
 * Dispatched from ProcessRawPost (key-gated, daily-capped) and from the
 * targeted historical classify command.
 */
class ClassifyPostWithLlm implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(public int $rawPostId)
    {
        $this->onQueue('pipeline');
    }

    /** Spend guardrail shared by live dispatch + backoff on retries. */
    public static function underDailyCap(): bool
    {
        return (int) Cache::get(self::capKey(), 0) < (int) config('pennyhunt.llm.max_per_day');
    }

    protected static function capKey(): string
    {
        return 'llm:classified:'.now()->toDateString();
    }

    public function handle(LlmPostClassifier $classifier): void
    {
        $post = RawPost::find($this->rawPostId);

        if ($post === null || ! $classifier->enabled()) {
            return;
        }

        if ($classifier->classifyAndStore($post)) {
            Cache::increment(self::capKey());
        }
    }
}
