<?php

namespace App\Jobs\Nlp;

use App\Models\MarketBrief;
use App\Services\Nlp\MarketBriefWriter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Regenerates the Desk market brief. Scheduled hourly during
 * market-relevant hours and dispatched on demand when the Desk renders
 * with a stale brief. Skips regeneration when the current brief is still
 * fresh so on-demand dispatches can't stack up spend.
 */
class GenerateMarketBrief implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public const FRESH_MINUTES = 45;

    public function __construct(public bool $force = false)
    {
        $this->onQueue('metrics');
    }

    public function handle(MarketBriefWriter $writer): void
    {
        if (! $this->force) {
            $current = MarketBrief::current();

            if ($current !== null && $current->generated_at->gt(now()->subMinutes(self::FRESH_MINUTES))) {
                return;
            }
        }

        $writer->write();
    }
}
