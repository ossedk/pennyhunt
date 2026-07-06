<?php

namespace App\Jobs\Ingestion;

use App\Models\Ticker;
use App\Services\MarketData\SqueezeFuelClients;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Polls the NASDAQ Trader trade-halts RSS (all US venues, incl. LULD
 * volatility halts) every 10 minutes during market hours. Halts are the
 * signature of parabolic small-cap moves — stored as point-in-time rows
 * for the halted_5d model feature and position alerts.
 */
class PollTradeHalts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(SqueezeFuelClients $clients): void
    {
        $halts = $clients->nasdaqHalts();

        if ($halts === []) {
            return;
        }

        $symbolMap = Ticker::query()
            ->whereIn('symbol', array_unique(array_column($halts, 'symbol')))
            ->pluck('id', 'symbol')
            ->all();

        $rows = array_map(fn (array $halt): array => [
            'symbol' => $halt['symbol'],
            'ticker_id' => $symbolMap[$halt['symbol']] ?? null,
            'halted_at' => $halt['halted_at'],
            'resumed_at' => $halt['resumed_at'],
            'reason' => $halt['reason'],
            'created_at' => now(),
            'updated_at' => now(),
        ], $halts);

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('trade_halts')->upsert($chunk, ['symbol', 'halted_at'], ['resumed_at', 'reason', 'ticker_id', 'updated_at']);
        }
    }
}
