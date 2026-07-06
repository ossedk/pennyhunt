<?php

namespace App\Services\Backtesting;

/**
 * Pure exit-discipline simulator: given the entry, the daily bars after it,
 * the signal-day ATR and the post-entry mention series, walks the holding
 * window under a configurable rule set and returns the realized outcome.
 * Shared by the Exit Lab (research grids), the Backtester and (via the
 * ported rules) the live TradeEngine — one implementation, no drift.
 *
 * Pessimistic daily-OHLC fill assumptions throughout:
 *  - a gap through the stop fills at the open (worse than the stop);
 *  - when one bar straddles stop and take, the stop fills first;
 *  - trailing exits trigger on the CLOSE that breaches (no intraday rescue);
 *  - partial takes fill at max(open, take price) — how limit orders fill.
 *
 * Config keys (all optional — defaults reproduce the legacy discipline):
 *  - stop_loss: fixed fractional stop (legacy 0.10). Ignored when
 *    atr_stop_mult is set.
 *  - atr_stop_mult: stop distance = k × ATR$ (clamped 5%..25% of entry).
 *  - stop_on_close: stop triggers on the CLOSE only (fill at that close) —
 *    immune to intraday shakeouts, pays for it via slippage past the level.
 *  - take_profit: full take-profit fraction (legacy behavior).
 *  - partial_take_at: sell HALF at this gain, move stop to breakeven,
 *    trail the remainder.
 *  - trail_atr_mult: trailing stop m × ATR$ below the peak close, armed
 *    once price has closed above entry (or after the partial fill).
 *  - max_hold: sessions before the time exit (legacy 5).
 *  - max_entry_gap: skip the trade entirely when the entry open gaps more
 *    than this fraction above the signal-day close.
 *  - mention_collapse_frac: exit at next open when daily mentions drop
 *    below this fraction of fire-day mentions (checked from day 2).
 */
class ExitSimulator
{
    /**
     * @param  array<string, mixed>  $config
     * @param  float  $entry  entry fill (open of bar index 0 in $bars)
     * @param  float  $signalClose  signal-day close (gap veto reference)
     * @param  float|null  $atrPct  ATR as fraction of price, as-of signal day
     * @param  array<int, array{date: string, open: float, high: float, low: float, close: float}>  $bars  entry day first
     * @param  array<int, int>  $mentionsByDay  offset (sessions after entry, 0-based) => mentions; -1 = fire day
     * @return array{skipped: bool, return: float|null, reason: string|null, day: int|null, date: string|null}
     */
    public function simulate(array $config, float $entry, float $signalClose, ?float $atrPct, array $bars, array $mentionsByDay = []): array
    {
        if ($entry <= 0 || $bars === []) {
            return ['skipped' => true, 'return' => null, 'reason' => null, 'day' => null, 'date' => null];
        }

        $maxGap = $config['max_entry_gap'] ?? null;

        if ($maxGap !== null && $signalClose > 0 && ($entry / $signalClose - 1) > (float) $maxGap) {
            return ['skipped' => true, 'return' => null, 'reason' => 'gap_veto', 'day' => null, 'date' => null];
        }

        $maxHold = (int) ($config['max_hold'] ?? 5);
        $atr = $atrPct !== null && $atrPct > 0 ? $atrPct * $entry : null;

        // Initial stop: ATR-scaled when configured and ATR is known,
        // otherwise the fixed fraction (when configured).
        $stopPrice = null;

        if (isset($config['atr_stop_mult']) && $atr !== null) {
            $distance = min(max((float) $config['atr_stop_mult'] * $atr, 0.05 * $entry), 0.25 * $entry);
            $stopPrice = $entry - $distance;
        } elseif (isset($config['stop_loss'])) {
            $stopPrice = $entry * (1 - (float) $config['stop_loss']);
        }

        $takePrice = isset($config['take_profit']) ? $entry * (1 + (float) $config['take_profit']) : null;
        $partialAt = isset($config['partial_take_at']) ? $entry * (1 + (float) $config['partial_take_at']) : null;
        $trailMult = isset($config['trail_atr_mult']) && $atr !== null ? (float) $config['trail_atr_mult'] : null;
        $collapseFrac = $config['mention_collapse_frac'] ?? null;
        $fireMentions = $mentionsByDay[-1] ?? null;

        $partialReturn = null; // realized on the first half, when partial fires
        $peakClose = $entry;
        $trailArmed = false;

        $blend = function (float $remainderReturn) use (&$partialReturn): float {
            return $partialReturn !== null
                ? round(0.5 * $partialReturn + 0.5 * $remainderReturn, 4)
                : round($remainderReturn, 4);
        };

        $last = min($maxHold, count($bars) - 1);

        for ($o = 0; $o <= $last; $o++) {
            $bar = $bars[$o];
            $open = $o === 0 ? $entry : $bar['open'];

            // Mention collapse (from day 2, needs the crowd series): the
            // fire-day buzz is gone — exit at this session's open.
            if ($collapseFrac !== null && $fireMentions !== null && $fireMentions > 0 && $o >= 2) {
                $mentions = $mentionsByDay[$o - 1] ?? 0; // prior session's full-day count

                if ($mentions < $collapseFrac * $fireMentions) {
                    return ['skipped' => false, 'return' => $blend(($open - $entry) / $entry), 'reason' => 'mention_collapse', 'day' => $o, 'date' => $bar['date']];
                }
            }

            // Stop first (pessimistic when a bar straddles both levels).
            if ($stopPrice !== null && ! ($config['stop_on_close'] ?? false)
                && ($open <= $stopPrice || $bar['low'] <= $stopPrice)) {
                $fill = min($open, $stopPrice);

                return ['skipped' => false, 'return' => $blend(($fill - $entry) / $entry), 'reason' => 'stop', 'day' => $o, 'date' => $bar['date']];
            }

            // Close-based stop: only a CLOSE through the level exits (at
            // that close) — intraday wicks don't shake us out.
            if ($stopPrice !== null && ($config['stop_on_close'] ?? false) && $bar['close'] <= $stopPrice) {
                return ['skipped' => false, 'return' => $blend(($bar['close'] - $entry) / $entry), 'reason' => 'stop', 'day' => $o, 'date' => $bar['date']];
            }

            // Full take-profit (legacy behavior).
            if ($takePrice !== null && ($open >= $takePrice || $bar['high'] >= $takePrice)) {
                $fill = max($open, $takePrice);

                return ['skipped' => false, 'return' => $blend(($fill - $entry) / $entry), 'reason' => 'take', 'day' => $o, 'date' => $bar['date']];
            }

            // Partial take: sell half, stop to breakeven, arm the trail.
            if ($partialAt !== null && $partialReturn === null && ($open >= $partialAt || $bar['high'] >= $partialAt)) {
                $fill = max($open, $partialAt);
                $partialReturn = ($fill - $entry) / $entry;
                $stopPrice = max($stopPrice ?? 0.0, $entry); // breakeven floor
                $trailArmed = true;
            }

            // Trailing stop on closes: armed after the first close above
            // entry (or the partial fill), trails the peak close.
            if ($trailMult !== null) {
                if ($bar['close'] > $entry) {
                    $trailArmed = true;
                }

                $peakClose = max($peakClose, $bar['close']);

                if ($trailArmed && $bar['close'] <= $peakClose - $trailMult * $atr && $bar['close'] > ($stopPrice ?? 0.0)) {
                    return ['skipped' => false, 'return' => $blend(($bar['close'] - $entry) / $entry), 'reason' => 'trail', 'day' => $o, 'date' => $bar['date']];
                }
            }
        }

        // Time exit at the last held close.
        $bar = $bars[$last];

        return ['skipped' => false, 'return' => $blend(($bar['close'] - $entry) / $entry), 'reason' => 'time', 'day' => $last, 'date' => $bar['date']];
    }
}
