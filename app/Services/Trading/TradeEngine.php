<?php

namespace App\Services\Trading;

use App\Events\TradeUpdated;
use App\Models\BacktestEvent;
use App\Models\MarketBar;
use App\Models\Signal;
use App\Models\SignalModel;
use App\Models\SignalTrade;
use App\Services\Features\TechnicalFeatures;

/**
 * The forward-test engine: turns trade-tier signals into paper positions and
 * manages them with the EXACT v3 discipline the backtest validated —
 * enter at the next session's open, 10% stop, no take-profit, time exit at
 * the day-5 close — using the same pessimistic daily-OHLC fill rules as
 * Backtester::simulateExit (a gap through the stop fills at the open).
 *
 * Authoritative state transitions happen only on completed daily bars, so
 * the live ledger and the research numbers can never diverge silently.
 * Intraday quotes (RefreshOpenTradeQuotes) are indicative display only.
 */
class TradeEngine
{
    /** Entry must materialize within this many days of the fire or the trade is void. */
    protected const ENTRY_TIMEOUT_DAYS = 7;

    /**
     * Open a pending paper trade when the signal scores at/above the active
     * model's trade tier. Null when below tier, unscored, or no tier is
     * defined (pre-GBM logistic models carry no validated threshold).
     */
    public function createForSignal(Signal $signal): ?SignalTrade
    {
        $model = SignalModel::active();
        $threshold = $model?->metrics['trade_tier']['calibrated_p'] ?? null;

        if ($threshold === null || $signal->confidence === null || $signal->confidence < $threshold) {
            return null;
        }

        $primary = null;

        // Two parallel paper books per tier signal: the validated legacy
        // discipline and the phase-E exit-lab discipline. Forward evidence
        // for both accumulates from the same entries; the legacy trade is
        // the primary (what the UI shows).
        foreach (['legacy', 'phase_e'] as $book) {
            $trade = SignalTrade::firstOrCreate(
                ['signal_id' => $signal->id, 'book' => $book],
                [
                    'ticker_id' => $signal->ticker_id,
                    'status' => 'pending_entry',
                    'tier' => 'trade',
                    'confidence_at_entry' => $signal->confidence,
                    'model_version' => $signal->model_version,
                    'kelly_fraction' => $this->kellyFraction($signal->confidence, $model),
                ],
            );

            TradeUpdated::dispatch($trade, 'created');

            $primary ??= $trade;
        }

        return $primary;
    }

    /** Fill pending entries and walk open positions over completed bars. */
    public function sync(): void
    {
        SignalTrade::query()
            ->where('status', 'pending_entry')
            ->with('signal')
            ->each(fn (SignalTrade $trade) => $this->fillEntry($trade));

        SignalTrade::query()
            ->where('status', 'open')
            ->each(fn (SignalTrade $trade) => $this->walkExits($trade));
    }

    protected function fillEntry(SignalTrade $trade): void
    {
        $firedDate = $trade->signal->fired_at->toDateString();

        $entryBar = MarketBar::query()
            ->where('ticker_id', $trade->ticker_id)
            ->where('interval', '1d')
            ->whereDate('bucket_start', '>', $firedDate)
            ->orderBy('bucket_start')
            ->first();

        if ($entryBar === null) {
            // Halted/delisted before entry: void the trade after a grace window.
            if ($trade->signal->fired_at->diffInDays(now()) > self::ENTRY_TIMEOUT_DAYS) {
                $trade->update(['status' => 'cancelled']);
                TradeUpdated::dispatch($trade, 'cancelled');
            }

            return;
        }

        $entry = (float) $entryBar->open;

        if ($entry <= 0) {
            $trade->update(['status' => 'cancelled']);
            TradeUpdated::dispatch($trade, 'cancelled');

            return;
        }

        // Phase-E book: no chasing — a large gap over the fire-day close
        // means we'd be paying the move we predicted. Veto the entry.
        if ($trade->book === 'phase_e') {
            $signalClose = (float) (MarketBar::query()
                ->where('ticker_id', $trade->ticker_id)
                ->where('interval', '1d')
                ->whereDate('bucket_start', '<=', $firedDate)
                ->orderByDesc('bucket_start')
                ->value('close') ?? 0);

            if ($signalClose > 0 && ($entry / $signalClose - 1) > SignalTrade::PHASE_E_MAX_ENTRY_GAP) {
                $trade->update(['status' => 'cancelled', 'exit_reason' => 'gap_veto']);
                TradeUpdated::dispatch($trade, 'cancelled');

                return;
            }
        }

        $trade->update([
            'status' => 'open',
            'entry_date' => $entryBar->bucket_start->toDateString(),
            'entry_price' => round($entry, 4),
            'stop_price' => round($this->initialStop($trade, $entry), 4),
        ]);

        TradeUpdated::dispatch($trade, 'opened');

        $this->walkExits($trade->refresh());
    }

    /**
     * Legacy book: fixed 10% stop. Phase-E book: 2x-ATR stop (clamped
     * 5–25%) — the stock's own noise floor, not a universal percentage.
     */
    protected function initialStop(SignalTrade $trade, float $entry): float
    {
        if ($trade->book !== 'phase_e') {
            return $entry * (1 - SignalTrade::STOP_FRACTION);
        }

        $bars = MarketBar::query()
            ->where('ticker_id', $trade->ticker_id)
            ->where('interval', '1d')
            ->orderByDesc('bucket_start')
            ->limit(20)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (MarketBar $b): array => [
                'date' => $b->bucket_start->toDateString(),
                'open' => (float) $b->open,
                'high' => (float) $b->high,
                'low' => (float) $b->low,
                'close' => (float) $b->close,
                'volume' => (float) $b->volume,
            ])
            ->all();

        $atrPct = count($bars) >= 15
            ? TechnicalFeatures::compute($bars, count($bars) - 1)['atr_pct']
            : null;

        $distance = $atrPct !== null
            ? min(max(SignalTrade::PHASE_E_ATR_STOP_MULT * min($atrPct, 1.0), 0.05), 0.25)
            : SignalTrade::STOP_FRACTION;

        return $entry * (1 - $distance);
    }

    /**
     * Same pessimistic rules as Backtester::simulateExit, on however many
     * completed bars exist so far. Also back-fills time_exit_date once the
     * fifth post-entry session is known.
     */
    protected function walkExits(SignalTrade $trade): void
    {
        $bars = MarketBar::query()
            ->where('ticker_id', $trade->ticker_id)
            ->where('interval', '1d')
            ->whereDate('bucket_start', '>=', $trade->entry_date)
            ->orderBy('bucket_start')
            ->limit(SignalTrade::HOLD_DAYS + 1)
            ->get();

        if ($bars->isEmpty()) {
            return;
        }

        if ($trade->time_exit_date === null && $bars->count() > SignalTrade::HOLD_DAYS) {
            $trade->update(['time_exit_date' => $bars[SignalTrade::HOLD_DAYS]->bucket_start->toDateString()]);
        }

        $entry = $trade->entry_price;
        $stop = $trade->stop_price;

        foreach ($bars as $offset => $bar) {
            // On the entry day the fill IS the entry open, so gap logic only
            // applies from day 1 onward (mirrors the Backtester).
            $open = $offset === 0 ? $entry : (float) $bar->open;

            if ($trade->book === 'phase_e') {
                // Close-based stop: only a CLOSE through the level exits —
                // intraday wicks don't shake the position out.
                if ((float) $bar->close <= $stop) {
                    $this->close($trade, $bar->bucket_start->toDateString(), (float) $bar->close, 'stop');

                    return;
                }
            } elseif ($open <= $stop || (float) $bar->low <= $stop) {
                $this->close($trade, $bar->bucket_start->toDateString(), min($open, $stop), 'stop');

                return;
            }

            if ($offset === SignalTrade::HOLD_DAYS) {
                $this->close($trade, $bar->bucket_start->toDateString(), (float) $bar->close, 'time');

                return;
            }
        }
    }

    protected function close(SignalTrade $trade, string $date, float $price, string $reason): void
    {
        $exitReturn = round(($price - $trade->entry_price) / $trade->entry_price, 4);

        $trade->update([
            'status' => 'closed',
            'exit_date' => $date,
            'exit_price' => round($price, 4),
            'exit_reason' => $reason,
            'exit_return' => $exitReturn,
            'net_return' => round($exitReturn - SignalTrade::FRICTION, 4),
            'unrealized_return' => null,
        ]);

        TradeUpdated::dispatch($trade, 'closed');
    }

    /**
     * Half-Kelly suggestion (advisory, capped at 10% equity): f* = p − (1−p)/b
     * with the payoff ratio b measured on the model's OWN training run —
     * fired events at/above the tier's raw probability, net of friction.
     */
    protected function kellyFraction(float $confidence, SignalModel $model): ?float
    {
        $rawTier = $model->metrics['trade_tier']['raw_p'] ?? null;
        $runId = $model->backtest_run_id;

        if ($rawTier === null || $runId === null) {
            return null;
        }

        $b = cache()->remember("trades:kelly_b:run{$runId}:tier{$rawTier}", now()->addDay(), function () use ($runId, $rawTier): ?float {
            $nets = BacktestEvent::query()
                ->where('backtest_run_id', $runId)
                ->where('fired', true)
                ->where('confidence', '>=', $rawTier)
                ->whereNotNull('exit_return')
                ->pluck('exit_return')
                ->map(fn ($r) => (float) $r - SignalTrade::FRICTION);

            $wins = $nets->filter(fn (float $r) => $r > 0);
            $losses = $nets->filter(fn (float $r) => $r < 0);

            if ($wins->count() < 5 || $losses->isEmpty()) {
                return null;
            }

            return $wins->avg() / abs($losses->avg());
        });

        if ($b === null || $b <= 0) {
            return null;
        }

        $full = $confidence - (1 - $confidence) / $b;

        return round(max(min($full / 2, 0.10), 0.0), 4);
    }
}
