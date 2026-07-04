# Two-regime flagship backtest — run #31 (2026-07-03)

> The regime-robustness test the decision gate required: the full 12-month
> archive (2025-07-03 → 2026-06-24, both halves continuous) through the
> previously-validated config (composite ≥ 0.65, price ≤ $5, volume z ≥ 2,
> 10% stop, no take, 5% friction), with the new 12-feature confidence
> pipeline (dilution / short-flow / regime) and Kelly portfolio simulation.

## Headline: the edge does NOT survive the second regime

| Window | Signals | Hit rate | Net/trade | Positive-net share |
| --- | --- | --- | --- | --- |
| Full 12 months | 420 | 16.7% (4.4× base 3.8%) | **−2.88%** | — (PF 0.74, 67% stopped) |
| 2025 H2 | 203 | 12.8% | **−5.80%** | 17% |
| 2026 H1 | 217 | 20.3% | **−0.14%** | 21% |

Two honest findings:

1. **2025 H2 was a losing regime.** The gates + stop discipline that netted
   +1.70%/trade on the 2026 H1-only run loses 5.8%/trade in 2025 H2. Hit
   rate collapses to 12.8%.
2. **2026 H1 itself shrinks under longer baselines.** The same half that
   showed +1.70%/trade when replayed with a cold 30-day warm-up nets −0.14%
   now that tickers carry real 2025 H2 mention history. Part of the earlier
   edge was baseline warm-up artifact (z-scores computed against sparse
   history fire more selectively), not market alpha.

**Decision gate status: NOT passed.** The v3 configuration is not a
deployable edge across regimes.

## Kelly behaved exactly as designed

| Strategy | Return | Max DD | Trades taken / skipped |
| --- | --- | --- | --- |
| Equal weight | −43.0% | 65.4% | 379 / 4 |
| Full Kelly | −9.3% | 10.0% | 55 / 328 |
| Half Kelly | −5.5% | 6.3% | 54 / 329 |

The as-of Kelly stats detected the negative expectancy and refused ~86% of
trades. Confidence-gated sizing turned a −43% account into a −5.5% account —
risk control works even when the signal doesn't.

## Confidence model: ranks well, still no absolute skill

Walk-forward (out-of-sample) quintiles are cleanly monotone: q1 realized
1.8% → q5 realized 8.0% (4.4× spread), predicted values track realized
within ~1pt. But Brier 0.04036 vs always-base-rate 0.04011 — the model
*ranks* candidates well and *levels* honestly, without beating climatology
overall. Same conclusion as v4: use it to rank/size, don't treat P(hit) as
gospel.

Activated live model `v2026-07-03-run31.2` (17,052 events, 12 features).
Standardized weights:

| Feature | Weight | Read |
| --- | --- | --- |
| log_dollar_volume | −0.249 | strongest: illiquid small names carry the tail |
| **atm_filed_90d** | **+0.201** | counterintuitive: a recent 424B takedown RAISES P(+30%) |
| pre_return_3d | +0.095 | momentum onset |
| zscore | +0.093 | mention acceleration |
| volume_z | +0.046 | volume confirmation |
| active_shelf / share_growth / short_ratio / market_ret_5d | +0.01…0.04 | weak positives |
| sentiment, site_mention_z, breadth | ≈ 0 / negative | still dead |

The ATM finding matches the winner profile (47% of winners had a 424B in
the prior 90 days vs 31% of losers): prospectus takedowns cluster around
catalysts/news and mark names that are *raising into attention* — as a
predictor of a pop it's positive, whatever it means for long-term holders.
Feature coverage on events: short_ratio 98.8%, share_growth 68.8%, regime
features 100%.

## Addendum: top-confidence-only policy simulation

Tested trading only fired signals whose walk-forward confidence clears the
trailing p50/p75/p90 of prior fired confidences (no look-ahead: cutoffs use
only past events, the confidence itself is walk-forward), plus fixed
absolute cutoffs.

| Policy | n | Hit | Net/trade | 2025 H2 | 2026 H1 |
| --- | --- | --- | --- | --- | --- |
| All fired (baseline) | 383 | 17.5% | −2.27% | −5.06% | −0.14% |
| ≥ trailing p50 | 255 | 18.4% | −3.55% | −6.17% | −1.59% |
| ≥ trailing p75 | 143 | 18.9% | **+0.45%** | −5.93% | +5.19% |
| ≥ trailing p90 | 73 | 21.9% | −3.90% | −10.29% | −0.14% |
| ≥ 0.08 absolute | 110 | 22.7% | +3.35% | — | — |
| ≥ 0.10 absolute | 45 | 26.7% | −2.85% | — | — |

Reading: **hit rate rises monotonically with confidence (17.5% → 26.7%) —
the ranking skill is confirmed — but net expectancy does not follow.**
Per-trade net is dominated by a handful of tail winners, so adjacent
thresholds flip sign (p75 positive, p90 negative; 0.08 positive, 0.10
negative). That non-monotonicity is a small-sample/overfit signature, not a
tradable rule, and no confidence tier survives 2025 H2. Confidence should
size and rank; it cannot rescue a configuration whose regime is wrong.

## What this means for the roadmap

1. The blocker is no longer evidence collection — it's **signal quality**.
   The plan's remaining levers, in priority order: LLM post classification
   (needs `ANTHROPIC_API_KEY`; post-type was never available to any model
   yet), author track-record as a model feature, regime-conditional firing
   (2026 H1 vs 2025 H2 difference suggests "only trade when the regime
   supports it" may be learnable — market_ret_5d got a positive weight),
   and a non-linear model (phase C) to exploit interactions the logistic
   can't see.
2. Ranking is real (4.4× q5/q1). A "top-decile-confidence only" policy is
   worth simulating before any live capital: q5 realized 8% hit with the
   same asymmetric exits may clear friction where the un-ranked firehose
   doesn't.
3. Live engine unchanged (gates + stop discipline for the forward test),
   but the activated 12-feature model now scores every fired signal, so the
   forward test accumulates ranked evidence.
