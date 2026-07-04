# Audit: Confidence scoring + Kelly position sizing (v4)

> Date: 2026-07-03
> Builds on: `2026-07-03-backtest-v3-exits.md` (exit design) and
> `2026-07-03-backtest-v2.md` (gates + weight fit)
> Reference run: #30 — gated (close ≤ $5, vol z ≥ 2) + 10% stop, 2026-01-03 → 2026-06-24

## What was built

1. **Confidence pipeline** (`ConfidenceTrainer`, `signal_models` table,
   `pennyhunt:train-confidence`):
   - *Walk-forward scoring*: monthly refits of a logistic P(hit | features)
     over a run's full candidate set (fired + control, no selection bias).
     Each month's events are scored by a model trained only on prior months —
     no look-ahead. Written to `backtest_events.confidence`. Runs
     automatically after every backtest.
   - *Live model*: a final fit on the full run is persisted and activated;
     the `SignalEngine` scores every fired signal at fire time
     (`signals.confidence` + `model_version`), using the exact same feature
     builder (mention z, volume z, sentiment, breadth, 3d pre-run, log
     dollar volume).
   - *Calibration is first-class*: Brier score vs the always-base-rate
     reference, plus a predicted-vs-realized reliability table by quintile.
2. **Kelly portfolio simulation** (`PortfolioSimulator`,
   `pennyhunt:simulate-portfolio`, auto-run after each backtest): replays a
   run's fired, confidence-scored trades chronologically against an equity
   curve ($100k start) comparing:
   - `equal` — 5% of equity per trade,
   - `kelly_half` / `kelly_full` — f* = p − (1−p)/b where p = realized
     net-win rate and b = avg net win / avg net loss, both estimated **only
     from trades closed before entry**, conditioned on the trade's
     confidence tercile. Confidence ranks; realized history prices.
   - Hard caps: 10% of equity per position and **1% of signal-day dollar
     volume** (the simulator refuses fills the market couldn't absorb).
     Positions held at cost (daily bars carry no intraday mark); PnL
     realized net of 5% friction at the simulated exit date.

## Results (run #30)

Strategy summary: 194 fired signals, hit rate 22.2%, +1.70% net/trade,
PF 1.15 — consistent with v3.

### Confidence quality (5,633 walk-forward-scored events)

| quintile | predicted | realized |
|---|---|---|
| q1 | 2.4% | 1.5% |
| q2 | 3.2% | 1.6% |
| q3 | 4.0% | 2.5% |
| q4 | 5.1% | 3.6% |
| q5 | 9.1% | **9.6%** |

- **Ranking works**: the top quintile realizes ~6x the bottom quintile's hit
  rate, and its calibration is nearly exact (9.1% predicted vs 9.6%
  realized).
- **Squared-error skill is nil**: Brier 0.03648 vs 0.03622 for always
  predicting the base rate. The model mildly over-predicts in the low
  quintiles. Translation: use confidence to *rank and gate*, do not treat
  the raw probability as gospel — which is exactly why the Kelly sizer
  prices bets from realized as-of history rather than from p directly.

### Portfolio simulation (173 scored trades)

| strategy | final equity | return | max drawdown | taken | skipped |
|---|---|---|---|---|---|
| equal 5% | $107,557 | +7.6% | **34.0%** | 173 | 0 |
| half Kelly | $100,318 | +0.3% | 12.9% | 38 | 135 |
| full Kelly | $113,391 | **+13.4%** | **12.9%** | 38 | 135 |

- Kelly's as-of history quickly concludes the lower confidence terciles
  have negative expectancy and **refuses 78% of trades** — it concentrates
  in the top tercile.
- Full Kelly beats equal weight on return **with ~1/3 of the drawdown**
  (+13.4% / −12.9% DD vs +7.6% / −34.0% DD). Half Kelly is roughly flat:
  with a thin edge, halving the fraction eats the whole margin.
- 6 equal-weight trades were liquidity-capped (1% of dollar volume) — at
  $100k equity the strategy fits inside these microcaps, but this cap is
  what will bind first if capital scales.

## Interpretation

The confidence → Kelly pipeline does what it was built for: it converts the
per-trade PF-1.13 edge into a portfolio result with materially better
risk-adjusted shape (return up, drawdown to a third). But the honest
caveats:

1. **38 trades is a small sample.** One or two tail winners drive full
   Kelly's outperformance; the difference between half and full Kelly here
   is not statistically meaningful.
2. **Single regime** — same Jan–Jun 2026 window as v2/v3. The 2025 H2
   backfill (running) must confirm before any of this is trusted.
3. Brier at climatology means the *level* of the probabilities carries no
   skill beyond ranking; Kelly inputs come from realized trade history for
   this reason, but that history is itself short.
4. Warm-up months share the equal-weight path (by design), so strategy
   separation only exists in the back half of the window.

## Decision-gate impact

Unchanged (NOT passed), but the toolchain for passing it is now complete:
signals carry a live confidence score, and every future backtest
automatically reports calibration + sized portfolio outcomes. Remaining
blockers are unchanged: second-regime evidence (2025 H2, in progress) and
live forward-test of the gated + stop discipline.
