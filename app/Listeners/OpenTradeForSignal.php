<?php

namespace App\Listeners;

use App\Events\SignalFired;
use App\Services\Trading\TradeEngine;

/**
 * Every fired signal at/above the active model's trade tier becomes a
 * pending paper trade immediately — the forward test must not depend on
 * anyone remembering to click something.
 */
class OpenTradeForSignal
{
    public function __construct(protected TradeEngine $engine) {}

    public function handle(SignalFired $event): void
    {
        $this->engine->createForSignal($event->signal);
    }
}
