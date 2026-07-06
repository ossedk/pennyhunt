<?php

namespace App\Services\MarketData;

use App\Models\MarketBar;
use App\Models\Ticker;
use Illuminate\Support\Facades\Cache;

/**
 * Extended-session quote from Polygon minute bars (4:00–20:00 ET,
 * 15-min delayed on Starter): the latest traded price with its change
 * vs the prior regular-session close, labeled with the session it
 * belongs to. Indicative display only — authoritative fills still come
 * from completed daily bars.
 */
class ExtendedQuote
{
    public function __construct(
        protected PolygonClient $polygon,
        protected MarketClock $clock,
    ) {}

    /**
     * @return array{price: float, change_pct: ?float, session: string, as_of: string, prev_close: ?float}|null
     */
    public function get(Ticker $ticker): ?array
    {
        $status = $this->clock->status()['status'];

        if ($status === MarketClock::STATUS_CLOSED || ! $this->polygon->enabled()) {
            return null;
        }

        return Cache::remember("extquote:{$ticker->id}", 120, function () use ($ticker, $status): ?array {
            $today = now()->setTimezone('America/New_York')->toDateString();

            $minutes = $this->polygon->minuteBars($ticker->symbol, $today);

            if ($minutes === []) {
                return null;
            }

            $last = end($minutes);

            // Prior regular-session close as the change reference.
            $prevClose = MarketBar::query()
                ->where('ticker_id', $ticker->id)
                ->where('interval', '1d')
                ->where('bucket_start', '<', $today)
                ->orderByDesc('bucket_start')
                ->value('close');

            $prevClose = $prevClose !== null ? (float) $prevClose : null;

            return [
                'price' => round($last['c'], 4),
                'change_pct' => $prevClose !== null && $prevClose > 0 ? round($last['c'] / $prevClose - 1, 4) : null,
                'session' => $status,
                'as_of' => gmdate('c', intdiv($last['t'], 1000)),
                'prev_close' => $prevClose,
            ];
        });
    }
}
