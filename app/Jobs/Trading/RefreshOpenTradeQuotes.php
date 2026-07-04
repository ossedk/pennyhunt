<?php

namespace App\Jobs\Trading;

use App\Events\TradeUpdated;
use App\Models\SignalTrade;
use App\Services\MarketData\YahooMarketData;
use App\Services\Trading\TradeAlerts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;

/**
 * 15-minute indicative quote refresh for OPEN positions only (a handful of
 * symbols). Sets unrealized P&L for the blotter/cockpit. Deliberately does
 * NOT close trades: an intraday stop breach shows as a UI warning while the
 * authoritative exit still comes from the completed daily bar — identical
 * semantics to the backtest, so live and research numbers never diverge.
 */
class RefreshOpenTradeQuotes implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct()
    {
        $this->onQueue('metrics');
    }

    public function handle(YahooMarketData $marketData, TradeAlerts $alerts): void
    {
        SignalTrade::query()
            ->where('status', 'open')
            ->with('ticker:id,symbol')
            ->each(function (SignalTrade $trade) use ($marketData, $alerts): void {
                $quote = $marketData->latestQuote($trade->ticker->symbol);

                if ($quote === null) {
                    return;
                }

                $trade->update([
                    'last_quote' => round($quote['price'], 4),
                    'last_quote_at' => $quote['at'],
                    'unrealized_return' => round($quote['price'] / $trade->entry_price - 1, 4),
                ]);

                $alerts->checkQuote($trade);

                TradeUpdated::dispatch($trade, 'quote');
                Sleep::for(250)->milliseconds();
            });
    }
}
