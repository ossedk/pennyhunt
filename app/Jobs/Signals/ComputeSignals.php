<?php

namespace App\Jobs\Signals;

use App\Services\Signals\SignalEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ComputeSignals implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('metrics');
    }

    public function handle(SignalEngine $engine): void
    {
        $engine->run();
    }
}
