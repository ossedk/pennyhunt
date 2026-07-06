<?php

namespace App\Services\Trading;

use App\Events\TradeUpdated;
use App\Models\BacktestEvent;
use App\Models\MarketBar;
use App\Models\Signal;
use App\Models\SignalModel;
use App\Models\SignalTrade;
use App\Support\AnalyticsGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        // Phase-E book: NO-CHASE veto — the lab's decisive finding is that
        // losses concentrate in entries after the move already started.
        // Skip when the stock ran > 15% over the 3 sessions pre-fire.
        if ($trade->book === 'phase_e') {
            $preRun = data_get($trade->signal->breakdown, 'market_gate.pre_return_3d');

            if ($preRun !== null && (float) $preRun > SignalTrade::PHASE_E_MAX_PRE_RUN) {
                $trade->update(['status' => 'cancelled', 'exit_reason' => 'pre_run_veto']);
                TradeUpdated::dispatch($trade, 'cancelled');

                return;
            }
        }

        $trade->update([
            'status' => 'open',
            'entry_date' => $entryBar->bucket_start->toDateString(),
            'entry_price' => round($entry, 4),
            // Phase-E carries NO price stop (every stop flavor destroyed
            // value in the lab); the mention-collapse exit and the 10-day
            // cap bound the risk instead.
            'stop_price' => $trade->book === 'phase_e' ? null : round($entry * (1 - SignalTrade::STOP_FRACTION), 4),
        ]);

        TradeUpdated::dispatch($trade, 'opened');

        $this->walkExits($trade->refresh());
    }

    /**
     * Same pessimistic rules as Backtester::simulateExit, on however many
     * completed bars exist so far. Also back-fills time_exit_date once the
     * book's final session is known.
     *
     * Legacy book: 10% stop (open/low breach fills pessimistically),
     * day-5 time exit. Phase-E book: no price stop; exits when the crowd
     * leaves (daily mentions < 25% of fire-day) or at the day-10 close.
     */
    protected function walkExits(SignalTrade $trade): void
    {
        $holdDays = $trade->book === 'phase_e' ? SignalTrade::PHASE_E_HOLD_DAYS : SignalTrade::HOLD_DAYS;

        $bars = MarketBar::query()
            ->where('ticker_id', $trade->ticker_id)
            ->where('interval', '1d')
            ->whereDate('bucket_start', '>=', $trade->entry_date)
            ->orderBy('bucket_start')
            ->limit($holdDays + 1)
            ->get();

        if ($bars->isEmpty()) {
            return;
        }

        if ($trade->time_exit_date === null && $bars->count() > $holdDays) {
            $trade->update(['time_exit_date' => $bars[$holdDays]->bucket_start->toDateString()]);
        }

        $entry = $trade->entry_price;
        $stop = $trade->stop_price;
        $collapse = $trade->book === 'phase_e' ? $this->mentionCollapseOffsets($trade, $bars) : [];

        foreach ($bars as $offset => $bar) {
            // On the entry day the fill IS the entry open, so gap logic only
            // applies from day 1 onward (mirrors the Backtester).
            $open = $offset === 0 ? $entry : (float) $bar->open;

            // Crowd-collapse exit (phase-E): the prior session's mentions
            // fell below the collapse floor — exit at this session's open.
            if (($collapse[$offset] ?? false) === true) {
                $this->close($trade, $bar->bucket_start->toDateString(), $open, 'mention_collapse');

                return;
            }

            if ($stop !== null && ($open <= $stop || (float) $bar->low <= $stop)) {
                $this->close($trade, $bar->bucket_start->toDateString(), min($open, $stop), 'stop');

                return;
            }

            if ($offset === $holdDays) {
                $this->close($trade, $bar->bucket_start->toDateString(), (float) $bar->close, 'time');

                return;
            }
        }
    }

    /**
     * Which session offsets (>= 2) open under a mention collapse: the full
     * prior session's mentions (weekends folded onto the next session)
     * dropped below PHASE_E_COLLAPSE_FRAC of the fire-day count.
     *
     * @param  Collection<int, MarketBar>  $bars
     * @return array<int, bool>
     */
    protected function mentionCollapseOffsets(SignalTrade $trade, $bars): array
    {
        $fireDay = $trade->signal->fired_at->toDateString();

        $gate = AnalyticsGate::mentionJoin('m');

        $rows = DB::select(<<<SQL
            SELECT date(m.posted_at) AS day, COUNT(*) AS mentions
            FROM post_ticker_mentions m
            {$gate}
            WHERE m.ticker_id = ? AND m.posted_at >= ?
            GROUP BY day
        SQL, [$trade->ticker_id, $fireDay]);

        $daily = [];

        foreach ($rows as $row) {
            $daily[(string) $row->day] = (int) $row->mentions;
        }

        $fireMentions = $daily[$fireDay] ?? 0;

        if ($fireMentions <= 0) {
            return [];
        }

        $floor = SignalTrade::PHASE_E_COLLAPSE_FRAC * $fireMentions;
        $out = [];
        $prevDate = $fireDay;

        foreach ($bars as $offset => $bar) {
            $date = $bar->bucket_start->toDateString();

            if ($offset >= 2) {
                // Mentions across the prior session's span (covers weekends).
                $sum = 0;

                for ($ts = strtotime($prevDate.' UTC'), $end = strtotime($date.' UTC'); $ts < $end; $ts += 86400) {
                    $sum += $daily[gmdate('Y-m-d', $ts)] ?? 0;
                }

                $out[$offset] = $sum < $floor && $date !== now()->toDateString();
            }

            $prevDate = $date;
        }

        return $out;
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
