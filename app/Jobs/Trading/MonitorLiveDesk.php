<?php

namespace App\Jobs\Trading;

use App\Models\Signal;
use App\Services\Trading\LiveDesk;
use App\Support\AlertMailer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Every 15 minutes during market sessions: run the LiveDesk assessment
 * over signals with live positions and email when a verdict FLIPS into an
 * action state (exit / exit_today / caution / stand_aside). Steady states
 * don't mail — only transitions are actionable.
 */
class MonitorLiveDesk implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    protected const ACTION_STATES = ['exit', 'exit_today', 'caution'];

    public function __construct()
    {
        $this->onQueue('metrics');
    }

    public function handle(LiveDesk $desk): void
    {
        $signals = Signal::query()
            ->whereHas('trades', fn ($q) => $q->whereIn('status', ['pending_entry', 'open']))
            ->with('ticker:id,symbol')
            ->get();

        foreach ($signals as $signal) {
            $assessment = $desk->assess($signal);

            if ($assessment['verdict'] === 'market_closed') {
                return; // session over — nothing to monitor
            }

            $previous = Cache::get("livedesk:verdict:{$signal->id}");
            Cache::put("livedesk:verdict:{$signal->id}", $assessment['verdict'], now()->addHours(12));

            if ($previous === $assessment['verdict'] || ! in_array($assessment['verdict'], self::ACTION_STATES, true)) {
                continue;
            }

            AlertMailer::send(
                sprintf('%s: live desk says %s', $signal->ticker->symbol, strtoupper(str_replace('_', ' ', $assessment['verdict']))),
                [
                    $assessment['headline'],
                    ...$assessment['reasons'],
                ],
                url("/signals/{$signal->id}"),
                'Open the live desk',
                "livedesk-flip:{$signal->id}:{$assessment['verdict']}",
                dedupeHours: 6,
            );
        }
    }
}
