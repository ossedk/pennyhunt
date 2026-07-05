<?php

namespace App\Jobs\Nlp;

use App\Services\Nlp\NewsCatalystClassifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Classifies unlabelled news headlines into catalyst types (hourly, after
 * SyncTrendingNews) so the news_catalyst_7d / news_offering_7d features and
 * the UI badges stay current.
 */
class ClassifyNewsCatalysts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $limit = 500)
    {
        $this->onQueue('default');
    }

    public function handle(NewsCatalystClassifier $classifier): void
    {
        $classifier->classifyPending($this->limit);
    }
}
