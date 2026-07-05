<?php

namespace App\Jobs\Nlp;

use App\Models\Signal;
use App\Services\Nlp\SignalBriefWriter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes the LLM "what to look for" note for one signal. Dispatched on
 * fire and lazily from the signal page when the brief is missing.
 */
class GenerateSignalBrief implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $signalId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->signalId;
    }

    public function handle(SignalBriefWriter $writer): void
    {
        $signal = Signal::find($this->signalId);

        if ($signal !== null && $signal->llm_brief === null) {
            $writer->write($signal);
        }
    }
}
