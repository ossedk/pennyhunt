<?php

namespace App\Jobs\Trading;

use App\Services\Trading\TradeAlerts;
use App\Services\Trading\TradeEngine;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nightly (after the 05:00 bar sync): fill pending entries from the new
 * session's open and walk open positions for stop / day-5 time exits on
 * completed daily bars — the authoritative trade lifecycle.
 */
class ManageSignalTrades implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('metrics');
    }

    public function handle(TradeEngine $engine, TradeAlerts $alerts): void
    {
        $engine->sync();

        // Risk nudges for what survived the sync: time exit tomorrow,
        // dilution filings since entry, collapsing mention flow.
        $alerts->checkOpenTrades();
    }
}
