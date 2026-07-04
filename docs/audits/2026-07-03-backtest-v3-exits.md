# Backtest v3 — Exit Design & Live Gating (2026-07-03)

> Third Phase 4 iteration. v2 ended with: "the edge is real but the naive
> buy-and-hold-5-days exit gives it all back on the losers." This iteration
> answers the exit question and promotes the validated gates into the live
> engine.

## Exit simulation methodology

`Backtester` now simulates stop-loss / take-profit exits on daily OHLC
(`params.stop_loss`, `params.take_profit`), stored per event as
`exit_return` / `exit_reason` (stop | take | time) / `exit_day`. Assumptions
are deliberately pessimistic:

- Entry-day (day 0) lows can stop you out immediately.
- A gap **through the stop fills at the open** (worse than the stop price).
- A gap through the take fills at the open too (that one is favorable — it's
  how a resting limit order fills).
- When a single bar straddles both levels, **the stop is assumed to fill
  first**.
- Nothing triggered by day 5 → time exit at the day-5 close (v2 behavior).

Net PnL (`avg_net_return_5d`, `profit_factor`) is computed on the simulated
exit minus 5% round-trip friction.

## Sweep results

All runs on the v2 best gated config (thr 0.65, ≥3 mentions/day,
price ≤ $5, volume z ≥ 2), window Jan 3 → Jun 24 2026, friction 5%
(runs #20–29):

| Exit rule | Signals | Net/trade | Net win rate | Profit factor | Stopped | Took | Timed |
|---|---|---|---|---|---|---|---|
| Time-only (5d hold, control) | 193 | −1.15% | 28.5% | 0.93 | — | — | 100% |
| **Stop 10%, no take** | **195** | **+1.39%** | 22.1% | **1.13** | 67% | — | 33% |
| Stop 15%, no take | 191 | +0.25% | 24.1% | 1.02 | 54% | — | 46% |
| Take 30%, no stop | 195 | −3.20% | 42.1% | 0.74 | — | 32% | 68% |
| Stop 10% + take 30% | 187 | −4.29% | 26.7% | 0.58 | 59% | 20% | 20% |
| Stop 15% + take 30% | 193 | −5.48% | 31.6% | 0.56 | 49% | 24% | 26% |
| Stop 10% + take 50% | 192 | −4.09% | 21.9% | 0.64 | 66% | 13% | 21% |
| Stop 15% + take 50% | 195 | −3.36% | 28.7% | 0.73 | 50% | 16% | 33% |
| Stop 20% + take 50% | 193 | −3.39% | 28.0% | 0.75 | 39% | 18% | 43% |
| Stop 10% + take 100% | 196 | −1.61% | 20.4% | 0.86 | 67% | 8% | 24% |

## Findings

1. **The asymmetry is exactly what the fat tail predicted: tight stop, NO
   take-profit.** A 10% stop flips the strategy from −1.15% to **+1.39% net
   per trade (profit factor 1.13)** — and every take-profit variant is
   *worse* than its no-take counterpart. The distribution's entire positive
   expectancy lives in the >30% runners; capping them at +30% (or even
   +100%) sells the only thing that pays for the 67% of trades that stop out.
2. **Take-profits look good and lose money.** Take 30% has the best win rate
   of the sweep (42%) and one of the worst PnLs (−3.20%). Win rate is
   actively misleading in this distribution — profit factor is the metric.
3. **Tighter stop beats wider.** 10% > 15% > 20%: losers rarely recover
   within the window, so waiting costs more than the extra whipsaw.
4. Caveat: intraday stop fills on daily OHLC are approximations. The gap
   handling is pessimistic, but a fast intraday spike-and-collapse can fill
   worse than any daily-bar model suggests on illiquid names. Treat +1.39%
   as an upper-middle estimate to be verified by the live forward test.

## Live engine changes (this iteration)

- `SignalEngine` now enforces the **market-confirmation gate** before firing:
  last close ≤ $5 AND latest volume z ≥ 2 (vs trailing 30 bars). Configured
  in `pennyhunt.signals.market_gate` (env `PENNYHUNT_MARKET_GATE`,
  `PENNYHUNT_MAX_ENTRY_PRICE`, `PENNYHUNT_MIN_VOLUME_Z`). Tickers with no
  bars get an on-demand Yahoo sync; still no data → no signal (can't price
  it, can't trade it). Gate inputs + verdict are stored in the signal
  breakdown for auditability.
- `pennyhunt:sync-market-bars --months=2 --min-mentions=2` scheduled daily
  05:00 so the gate normally reads fresh bars.
- New paper-trading discipline implied by this audit: enter next open,
  10% stop, no take, time-exit day 5. This is what the Signals page forward
  test should be judged against.

## Decision gate status

**Improved, still not passed.** The gated + stop-managed configuration is
net-positive (+1.39%/trade, PF 1.13) on 6 months of replay with pessimistic
fills — the first configuration that survives friction. But PF 1.13 is thin,
one regime window, and stop-fill quality on illiquid penny names is the
biggest unmodeled risk. Next: (1) second window (2025 H2) regime check,
(2) live forward test with the now-gated engine, (3) FinBERT sidecar since
sentiment remains a dead component.

Sweep is reproducible via `php artisan tinker scripts/exit_sweep.php`.
