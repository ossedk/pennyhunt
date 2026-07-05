<?php

namespace Tests\Unit;

use App\Console\Commands\BackfillTwitterHistory;
use PHPUnit\Framework\TestCase;

class BackfillTwitterWindowsTest extends TestCase
{
    protected function merge(array $days): array
    {
        $command = new BackfillTwitterHistory;
        $method = new \ReflectionMethod($command, 'mergeWindows');

        return $method->invoke($command, $days);
    }

    public function test_close_candidate_days_merge_into_one_window(): void
    {
        // Days 2 apart → padded windows overlap → single episode.
        $windows = $this->merge(['2025-03-10', '2025-03-12', '2025-03-14']);

        $this->assertSame([['2025-03-07', '2025-03-16']], $windows);
    }

    public function test_distant_episodes_stay_separate(): void
    {
        $windows = $this->merge(['2025-03-10', '2025-06-20']);

        $this->assertSame([
            ['2025-03-07', '2025-03-12'],
            ['2025-06-17', '2025-06-22'],
        ], $windows);
    }
}
