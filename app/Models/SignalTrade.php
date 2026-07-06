<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paper trade opened for a trade-tier signal and managed by the validated
 * v3 discipline (next-open entry, 10% stop, no take-profit, day-5 time
 * exit). The collection of these rows IS the forward test: the live,
 * out-of-sample evidence the Phase 4 decision gate needs.
 */
class SignalTrade extends Model
{
    public const FRICTION = 0.05;

    /*
    | Phase-E book discipline — the exit lab's only both-halves-positive
    | cell on honest GBM walk-forward tiers (run 35, tier >= 0.15,
    | n=55, +5.8% net/trade, PF 1.82):
    |   - NO-CHASE entry veto: skip when the stock already ran > 15%
    |     in the 3 sessions before the fire (chasers own the losses);
    |   - NO price stop: stops of every flavor destroyed value — the
    |     10-session time cap bounds the disaster case instead;
    |   - MENTION-COLLAPSE exit: leave when daily mentions drop below
    |     25% of the fire day (the crowd leaving is the real stop);
    |   - time exit at the day-10 close.
    */
    public const PHASE_E_MAX_PRE_RUN = 0.15;

    public const PHASE_E_COLLAPSE_FRAC = 0.25;

    public const PHASE_E_HOLD_DAYS = 10;

    public const STOP_FRACTION = 0.10;

    public const HOLD_DAYS = 5;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
            'time_exit_date' => 'date:Y-m-d',
            'exit_date' => 'date:Y-m-d',
            'entry_price' => 'float',
            'stop_price' => 'float',
            'exit_price' => 'float',
            'exit_return' => 'float',
            'net_return' => 'float',
            'confidence_at_entry' => 'float',
            'kelly_fraction' => 'float',
            'last_quote' => 'float',
            'last_quote_at' => 'datetime',
            'unrealized_return' => 'float',
        ];
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function ticker(): BelongsTo
    {
        return $this->belongsTo(Ticker::class);
    }

    /** Trading days held so far (bars since entry), for the day-N/5 counter. */
    public function holdingDay(): ?int
    {
        if ($this->entry_date === null) {
            return null;
        }

        if ($this->status === 'closed') {
            return null; // exit_date/exit_reason tell the story
        }

        return MarketBar::query()
            ->where('ticker_id', $this->ticker_id)
            ->where('interval', '1d')
            ->whereDate('bucket_start', '>', $this->entry_date)
            ->count();
    }
}
