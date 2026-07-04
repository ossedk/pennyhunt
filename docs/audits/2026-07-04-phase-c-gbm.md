# Phase C — kill switches + non-linear model (GBM) on run #32 — 2026-07-04

Two questions, answered on the 24-month flagship event set (32,619 events,
719 fired signals, 2024-08 → 2026-06):

1. Do the regime kill switches from the macro audit (VIX ≥ 25, site-buzz
   z ≥ 1.5) rescue the configuration cheaply?
2. Does a non-linear model (gradient boosting + isotonic calibration) beat
   the walk-forward logistic regression — and does its top tier reach
   positive expectancy?

Tooling: `scripts/regime_kill_switch_analysis.php` (kill switches),
`scripts/phase_c_gbm.py` + `scripts/phase_c_robustness.py` (Python,
`.venv-ml` with scikit-learn 1.9). Event export:
`storage/app/phase_c_events.csv` (all 15 `ConfidenceTrainer` features +
labels + exit returns, regenerable via the export snippet in the scripts).

## 1. Kill switches: mostly a no

- **VIX ≥ 25 did NOT replicate.** On run #31 it was the worst bucket
  (−11.5%/trade); on the 24-month window the *killed* bucket outperformed
  the kept one (PF 0.92 vs 0.57). The run #31 finding was regime-specific
  noise, not a rule. The VIX threshold sweep (18–30) finds nothing that
  flips expectancy.
- **Site-buzz z ≥ 1.5 is directionally real** — the killed bucket is
  clearly bad (PF 0.44, hit rate 8.6%) — but removing it only lifts the
  kept book from PF 0.59 to ~0.63. Confirms the feature (already
  negatively weighted in the model); useless as a rescue.
- Stacking confidence tiers on top of the switches never goes positive
  (best sweep ≈ −2.9%/trade, PF 0.74 — and that's threshold-mining).

**Verdict: regime gates trim the loss tail but cannot flip the sign.**
The macro information belongs *inside* the model as features (where it
already is), not as discrete gates.

## 2. GBM vs logistic (walk-forward, monthly refits, no look-ahead)

Same protocol as `ConfidenceTrainer`: each month scored by a model trained
only on prior months, ≥300 training events before scoring. 31,768 events
scored out-of-sample. Models: standardized logistic (baseline, matches the
PHP implementation), `HistGradientBoostingClassifier` (shallow, heavily
regularized), and the same GBM wrapped in isotonic calibration (3-fold).

| Model | Brier | Top-decile realized hit | Bottom-decile | Lift (d10/d1) |
|---|---|---|---|---|
| Base-rate reference | 0.03936 | — | — | — |
| Logistic (15 features) | 0.03972 | 9.9% | 1.9% | 5.2× |
| **GBM** | **0.03832** | **13.1%** | 0.5% | **24×** |
| GBM + isotonic | 0.03822 | 12.0% | 0.8% | 15× |

This is the first model that **beats the base-rate Brier** (logistic never
did, across four runs). The GBM's decile table is monotone from 0.5% to
13.1% realized — it concentrates the winners far harder than the logistic,
which saturates around 10% in the top decile. Isotonic calibration
slightly improves probability *levels* (useful for Kelly) at a small cost
in raw discrimination.

Permutation importance (holdout): `pre_return_3d` and `log_dollar_volume`
dominate, then `vix` and `market_ret_5d` — the macro features earn their
seat inside the model even though they fail as gates. `sentiment`,
`breadth`, `mention_streak` contribute ≈ nothing (consistent with every
weight fit since v2).

## 3. Policy simulation on fired signals (709 scored, 5% friction)

Baseline: all fired = −4.44%/trade, PF 0.60. Causal policies only
(thresholds decidable at fire time — fixed absolute probability, or
expanding-window percentile of prior fired scores):

| Policy | n | Hit | Avg net | Bootstrap CI90 | PF |
|---|---|---|---|---|---|
| GBM p ≥ 0.10 | 309 | 16.2% | −1.6% | [−5.7%, +3.1%] | 0.86 |
| **GBM p ≥ 0.15** | 144 | 22.9% | **+2.7%** | [−5.2%, +12.4%] | 1.23 |
| GBM ≥ expanding p75 | 145 | 23.4% | **+4.1%** | [−4.1%, +14.6%] | 1.35 |
| GBM+iso ≥ expanding p90 | 109 | 17.4% | +2.4% | [−6.3%, +13.3%] | 1.22 |

Half-year stability of the p ≥ 0.10 tier: +0.4% (2024-H2), **−9.7%
(2025-H1)**, −1.0% (2025-H2), +2.2% (2026-H1). The positive tiers are
carried by fat-tail winners; 2025-H1 remains lethal for everything.

## Verdict

1. **The GBM is a genuine model upgrade** — first Brier below base rate,
   24× top/bottom decile separation vs 5× for logistic. It should become
   the confidence scorer.
2. **Positive expectancy is now visible but not proven.** The best causal
   top-tier policies average +2.7% to +4.1% net/trade over 24 months —
   but every bootstrap CI90 still includes zero, and 2025-H1 is negative
   for every tier. This is "promising", not "deployable". Contrast with
   the logistic model, whose best tier never went positive at all.
3. Kill switches are dead as a strategy lever; VIX ≥ 25 was overfit to
   run #31.

## Addendum 2026-07-04: GBM productionized and ACTIVE

The GBM is now the live confidence scorer — **`gbm-v2026-07-04-run32.4`**,
trained and activated the same day:

- **Training**: `pennyhunt:train-gbm {--run=} {--activate}` exports the
  run's events, invokes `scripts/train_gbm_model.py` (`.venv-ml` python,
  `pennyhunt.ml.python` config), which (1) walk-forward scores for honest
  metrics, (2) fits the final GBM on the full run, (3) fits isotonic
  calibration on the *out-of-sample* scores only, and (4) exports a JSON
  artifact (flattened tree nodes + isotonic breakpoints + parity vectors).
- **Serving is pure PHP**: `SignalModel::predict()` now dispatches on
  `parameters.type` — logistic stays as-is; `gbm` walks the 300 trees,
  sigmoids the summed log-odds, and interpolates the isotonic curve.
  ~10ms per prediction, no Python on the live path. Parity vectors are
  re-scored through the PHP evaluator at import; mismatch aborts.
- **Calibration bonus**: the isotonic layer improves the walk-forward
  Brier further, 0.03832 → **0.03774** (ref 0.03936), with near-perfect
  decile reliability (e.g. decile 10 predicted 13.3% vs realized 13.1%).
  Calibrated probabilities are what Kelly sizing needs.
- The research trade tier raw p ≥ 0.15 maps to **calibrated p ≥ 0.124**
  (stored in the model's `metrics.trade_tier`) — the live paper-trade
  filter going forward.
- Tests: `tests/Unit/SignalModelGbmTest.php` (tree routing, missing
  features, isotonic interpolation/clipping, logistic fallback); existing
  SignalEngine/ConfidenceTrainer/PortfolioSimulator suites green.

## Follow-ups

- Feed the LLM post-type/conviction/pump-suspicion features and author
  track records into the GBM — run #32 backfill of ~139k candidate-day
  posts started 2026-07-04 (`storage/logs/classify-posts-run32.log`);
  retrain with the new feature block when it lands.
- Forward test: paper-trade only the calibrated p ≥ 0.124 tier,
  accumulate out-of-sample evidence before risking capital.
- Phase D friction realism: at PF 1.23–1.35 the strategy lives or dies on
  the 5% flat friction assumption; minute-bar fills will decide it.
