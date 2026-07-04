# Audit — Macro regime, momentum continuation & company fundamentals (2026-07-03)

Follow-up to the [two-regime flagship](2026-07-03-two-regime-flagship.md).
Three user questions drove this work: (1) is macro killing signals in the
backtest, (2) do we account for *building* momentum/sentiment (a +100%/5d move
needs continuation, not a one-shot spike), and (3) expand the stock page with
company/accounting data. A fourth change of plan: the LLM classifier now runs
on OpenAI (user supplied `OPENAI_API_KEY`), and Polygon Stocks Starter is live.

## 1. New point-in-time features (now 15 total)

| Feature | Definition | Source |
| --- | --- | --- |
| `vix` | CBOE VIX close, last session at/before signal day (≤6d stale) | Yahoo `^VIX`, keyless |
| `btc_ret_5d` | Bitcoin 5-session return — retail risk-appetite proxy | Yahoo `BTC-USD`, keyless |
| `mention_streak` | Consecutive days of strictly rising mentions ending at the signal day (cap 7); 0 = spike not building | own archive |

All three live in `MarketIntelligence` (same instance serves Backtester and
SignalEngine — no research/live drift), are persisted on `backtest_events`,
feed `ConfidenceTrainer::FEATURES`, and were backfilled onto run #31's 17,052
events. `pennyhunt:sync-market-bars` now always rides `^VIX` and `BTC-USD`
along with IWM (24 months backfilled: 524 VIX bars, 761 BTC bars).

## 2. Does macro kill signals? (run #31, fired set, net of 5% friction)

| Slice | n | hit | avg net exit |
| --- | --- | --- | --- |
| IWM 5d ≤ −2% (risk-off) | 42 | 26.2% | **+8.05%** |
| IWM 5d −2%..+2% | 261 | 13.4% | −3.64% |
| IWM 5d ≥ +2% (risk-on) | 117 | 20.5% | −5.08% |
| VIX < 15 | 44 | 18.2% | −0.87% |
| VIX 15–20 | 296 | 16.2% | −1.84% |
| VIX 20–25 | 57 | 15.8% | −6.31% |
| VIX ≥ 25 (stress) | 23 | 21.7% | **−11.54%** |
| site z < 0 (casino cooling) | 111 | 19.8% | **+0.01%** |
| site z ≥ 1.5 (casino hot) | 72 | 11.1% | −4.75% |

Findings (small-sample caveats apply, especially n=23–46 buckets):

- **VIX stress is a genuine killer.** Above VIX 20 the net decays fast; at
  VIX ≥ 25 the hit rate is fine (21.7%) but the P&L is the worst bucket
  (−11.5%/trade) — winners still happen, but losers gap through stops.
  A "no new entries when VIX ≥ 25" rule would have avoided the worst tail.
- **Site-wide frenzy is contrarian-negative.** When the whole casino runs hot
  (site z ≥ 1.5) hit rate halves (11.1% vs 19.8% cooling). Crowded attention
  is late attention. The model already prices this (site_mention_z weight is
  negative), but it also works as a discretionary caution flag.
- **Counterintuitive: risk-off IWM tape was the best bucket** (+8.05%). In a
  weak tape fewer candidates pass the gates, and those that do are
  idiosyncratic stories rather than beta. Do NOT read this as "trade more in
  crashes" — n=42 and one regime. But it argues against a naive
  "only trade risk-on" filter.
- BTC risk-on (≥ +5%/5d) was net-negative (−6.9%) — retail euphoria coincides
  with late entries. Standardized weight came out mildly positive (+0.07),
  so the interactions are not linear; the logistic sees it jointly with VIX.

**Answer: macro explains part of the loss tail (VIX stress + crowded casino),
not the whole invalidation.** The regime features are now priced into every
confidence score, and the retrained model uses them.

## 3. Momentum continuation (mention_streak)

Among ALL 17k candidates the hit base rate rises monotonically with streak:
3.5% (streak 0) → 4.3% (1) → 4.1% (2) → 4.7% (3+). Real but mild signal —
standardized weight +0.017, near the bottom of the table.

Among the 445 fired signals the pattern is noisy (streak-1 best at +0.6% net;
streak-2 worst at −9.3%) — n per bucket is too small to conclude. What we can
say honestly: **we now measure continuation explicitly** (before, only the
single-day z-score and pre_return_3d proxied it), the live engine scores it,
and two more quarters of forward data will tell us if it earns a bigger weight.

Note the *price* side of continuation was already covered (`pre_return_3d`,
which carries a +0.095 weight — one of the strongest positives: winners enter
with the price move already confirming).

## 4. Retrained confidence model (v2026-07-03-run31.3, active)

15 features, 17,052 events. Holdout reliability stays monotone: bucket 1
predicted 2.6%/realized 1.4% → bucket 5 predicted 8.7%/realized 9.3%.
Notable standardized weights: `log_dollar_volume` −0.249 (illiquidity wins),
`atm_filed_90d` +0.200 (ATM selling coincides with pumpable floats — a flag
to watch, not a buy reason), `pre_return_3d` +0.095, `zscore` +0.092,
`btc_ret_5d` +0.073, `vix` −0.044.

## 5. LLM classification switched to OpenAI

`LlmPostClassifier` now supports both providers (OpenAI preferred when both
keys exist): `gpt-5-mini`, `reasoning_effort: minimal`,
`response_format: json_object`. Live smoke test correctly flagged a pump post
(hype, pump_suspicion 0.9). Historical backfill of run #31 candidate-day
posts (79,092 posts, ~$25 one-off, ~1.2s/post) is running in the background —
`storage/logs/classify-posts.log`. When it finishes, LLM-derived features
(post_type shares, conviction, catalyst claims) become trainable on run #31.

## 6. Company fundamentals & stock page (Polygon Stocks Starter)

New tables `ticker_profiles` + `ticker_financials`; `PolygonClient`
(ticker details v3 + vX financials) and `SyncCompanyProfile` job — dispatched
lazily from the ticker page when missing/stale (>7 days), so Polygon requests
are spent only on viewed names. The ticker page now shows:

- **Company** — description, industry (SIC), market cap, shares outstanding,
  employees, listing date, HQ, homepage.
- **Financials (quarterly)** — revenue, net income, EPS, operating cash flow,
  assets, liabilities, equity from SEC XBRL. Red highlights for losses and
  negative equity (going-concern smell test for penny stocks).
- **Dilution & short flow** — the exact as-of features the model sees
  (short ratio, active shelf, 424B takedown, 12m share growth, IWM regime,
  site buzz z).
- **X/Twitter verified voices** — verified-profile tweets mentioning the
  ticker ranked by likes (true engagement). Renders an empty state until the
  paid Apify Twitter poller is enabled; ingestion already stores
  `isBlueVerified` + like counts, so the panel lights up with no further work.

## Verification

- 97 backend tests pass (`--parallel`), including 2 new MarketIntelligence
  tests (VIX/BTC as-of semantics incl. weekend carry-forward; streak
  build/break) and an OpenAI-provider classifier test.
- `tsc --noEmit`, ESLint, Pint clean.
- Browser-verified `/tickers/GME`: company card, financials table (Q1-2027
  rev $835.3M / NI $389.6M matches GameStop's actual filing), dilution card
  (60% short ratio, active shelf yes), Twitter empty state.

## Follow-ups

1. When the 79k-post LLM backfill completes: add `llm_*` aggregates as
   event features and retrain (the original phase-B thesis).
2. Consider a hard `VIX >= 25` no-entry gate after one more quarter of
   evidence (currently only priced, not gated).
3. Twitter verified-voices panel needs the paid Apify plan to populate.
4. 24-month Reddit backfill still running; re-run the flagship + trainer on
   the longer window when done.
