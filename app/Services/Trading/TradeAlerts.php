<?php

namespace App\Services\Trading;

use App\Models\AlertEvent;
use App\Models\SecFiling;
use App\Models\SignalTrade;
use App\Models\TickerMetric;

/**
 * System-generated risk alerts for open paper positions — the "should I
 * still be in this?" nudges the plan calls for:
 *
 *  - trade_stop_proximity: indicative quote within 3% of the stop
 *  - trade_time_exit_next: the day-5 time exit is the next session
 *  - trade_new_filing: dilution paper (S-1, S-3, 424B, EFFECT) since entry
 *  - trade_mention_collapse: daily mentions dropped >70% from the fire day
 *
 * Alerts dedupe to one per trade+kind per day and are advisory: the trade
 * engine itself never acts on them (exits stay on completed daily bars).
 */
class TradeAlerts
{
    protected const DILUTION_FORMS = ['S-1', 'S-3', 'S-3/A', 'S-1/A', '424B3', '424B4', '424B5', 'EFFECT', 'F-1', 'F-3'];

    /** Intraday: called after each quote refresh. */
    public function checkQuote(SignalTrade $trade): void
    {
        if ($trade->status !== 'open' || $trade->last_quote === null || $trade->stop_price === null || $trade->last_quote <= 0) {
            return;
        }

        $distance = ($trade->last_quote - $trade->stop_price) / $trade->last_quote;

        if ($distance > 0.03) {
            return;
        }

        $this->record($trade, 'trade_stop_proximity', [
            'last_quote' => $trade->last_quote,
            'stop_price' => $trade->stop_price,
            'distance' => round($distance, 4),
        ]);
    }

    /** Nightly: called after the trade engine sync, on completed bars. */
    public function checkOpenTrades(): void
    {
        SignalTrade::query()
            ->where('book', 'legacy')
            ->where('status', 'open')
            ->with(['ticker:id,symbol', 'signal:id,fired_at'])
            ->each(function (SignalTrade $trade): void {
                $this->checkTimeExitNext($trade);
                $this->checkNewFilings($trade);
                $this->checkMentionCollapse($trade);
            });
    }

    protected function checkTimeExitNext(SignalTrade $trade): void
    {
        $day = $trade->holdingDay();

        if ($day !== SignalTrade::HOLD_DAYS - 1) {
            return;
        }

        $this->record($trade, 'trade_time_exit_next', ['holding_day' => $day]);
    }

    protected function checkNewFilings(SignalTrade $trade): void
    {
        $filings = SecFiling::query()
            ->where('ticker_id', $trade->ticker_id)
            ->where('filed_at', '>=', $trade->entry_date)
            ->whereIn('form', self::DILUTION_FORMS)
            ->orderByDesc('filed_at')
            ->get(['form', 'filed_at']);

        if ($filings->isEmpty()) {
            return;
        }

        $this->record($trade, 'trade_new_filing', [
            'forms' => $filings->map(fn (SecFiling $f) => ['form' => $f->form, 'filed_at' => $f->filed_at->toDateString()])->all(),
        ]);
    }

    protected function checkMentionCollapse(SignalTrade $trade): void
    {
        $firedDay = $trade->signal->fired_at->toDateString();

        $baseline = TickerMetric::query()
            ->where('ticker_id', $trade->ticker_id)
            ->where('interval', '1d')
            ->whereDate('bucket_start', $firedDay)
            ->value('mention_count');

        $latest = TickerMetric::query()
            ->where('ticker_id', $trade->ticker_id)
            ->where('interval', '1d')
            ->whereDate('bucket_start', '<', now()->toDateString())
            ->orderByDesc('bucket_start')
            ->value('mention_count');

        if ($baseline === null || $baseline < 5 || $latest === null) {
            return;
        }

        $drop = 1 - $latest / max($baseline, 1);

        if ($drop < 0.7) {
            return;
        }

        $this->record($trade, 'trade_mention_collapse', [
            'fired_day_mentions' => $baseline,
            'latest_mentions' => $latest,
            'drop' => round($drop, 2),
        ]);
    }

    /** @param array<string, mixed> $context */
    protected function record(SignalTrade $trade, string $kind, array $context): void
    {
        $alreadyToday = AlertEvent::query()
            ->where('signal_trade_id', $trade->id)
            ->where('kind', $kind)
            ->whereDate('created_at', now()->toDateString())
            ->exists();

        if ($alreadyToday) {
            return;
        }

        AlertEvent::create([
            'alert_rule_id' => null,
            'signal_id' => $trade->signal_id,
            'signal_trade_id' => $trade->id,
            'kind' => $kind,
            'payload' => [
                'symbol' => $trade->ticker->symbol,
                ...$context,
            ],
        ]);
    }
}
