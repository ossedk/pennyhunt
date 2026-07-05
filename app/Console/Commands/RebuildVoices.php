<?php

namespace App\Console\Commands;

use App\Services\Metrics\AuthorCallGrader;
use App\Support\Memory;
use Illuminate\Console\Command;

/**
 * On-demand voices rebuild (the weekly job does the same on Mondays):
 * open calls from new mentions, grade resolved windows, snapshot boards.
 */
class RebuildVoices extends Command
{
    protected $signature = 'pennyhunt:rebuild-voices';

    protected $description = 'Open, grade and snapshot the Voices leaderboards now';

    public function handle(AuthorCallGrader $grader): int
    {
        Memory::raise('1024M');

        $this->line('opened: '.$grader->openCalls());
        $this->line('graded: '.$grader->gradeCalls());
        $this->line('ranked: '.$grader->snapshot().' rows');

        return self::SUCCESS;
    }
}
