<?php

namespace App\Jobs\Metrics;

use App\Services\Metrics\AuthorCallGrader;
use App\Support\Memory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Weekly voices refresh: open calls from new mentions, grade the pending
 * ones whose forward window has resolved, and snapshot the ranked board.
 * Scheduled Mondays after the daily bar sync so grades use complete weeks.
 */
class BuildAuthorLeaderboard implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct()
    {
        $this->onQueue('metrics');
    }

    public function handle(AuthorCallGrader $grader): void
    {
        Memory::raise('1024M');

        $opened = $grader->openCalls();
        $graded = $grader->gradeCalls();
        $ranked = $grader->snapshot();

        Log::info('Author leaderboard built', [
            'opened' => $opened,
            'graded' => $graded,
            'ranked' => $ranked,
        ]);
    }
}
