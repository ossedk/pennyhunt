# Signal-quality expansion — Phase A + B infrastructure (2026-07-03)

> Implements the first two phases of the "missing links" plan: dilution &
> solvency risk (SEC EDGAR), squeeze mechanics (FINRA Reg SHO), regime
> context, LLM post classification, and author track records. All Phase A
> sources are free and keyless. Point-in-time correctness is the design
> center: every feature is computable as-of any historical day with zero
> look-ahead, so one data sync serves both backtests and the live engine.

## What was built

### New tables

| Table | Contents | Point-in-time key |
| --- | --- | --- |
| `sec_filings` | Dilution-relevant EDGAR filings (S-3/F-3 shelves, 424B3/B4/B5 takedowns, S-1/F-1, 8-K, 10-K/Q, 20-F, NT) | `filed_at` |
| `ticker_share_counts` | XBRL cover-page shares outstanding per report | `as_of` |
| `short_volumes` | FINRA Reg SHO daily short/total volume + ratio (CNMS + CORF summed) | `day` |
| `tickers.cik` | SEC CIK from company_tickers.json (10,415 / 10,445 mapped) | — |

### New features (on `backtest_events`, in `ConfidenceTrainer::FEATURES`, scored live)

| Feature | Definition | Hypothesis |
| --- | --- | --- |
| `short_ratio` | Reg SHO short volume / total volume, latest session ≤ day (max 6d stale) | high off-exchange shorting → squeeze fuel vs distribution |
| `active_shelf` | S-3/F-3 filed within 3 years | dilution capacity on the shelf caps upside |
| `atm_filed_90d` | 424B3/B4/B5 within 90 days | shares actively sold into strength — pumps get sold on |
| `share_growth_12m` | shares outstanding now vs ≥12 months prior | realized dilution — chronic diluters kill momentum |
| `market_ret_5d` | IWM 5-session return | small-cap risk appetite regime |
| `site_mention_z` | site-wide daily mention count vs trailing 30d | "whole casino running hot" — breadth of the mania |

The feature builder was refactored to a named-input array
(`ConfidenceTrainer::features(array $in)`) shared by trainer, WeightFitter
and SignalEngine. Old persisted models keep working: `SignalModel::predict`
iterates over the *stored* weight keys, so a 6-feature model ignores the new
inputs until retrained.

### Phase B infrastructure (key-gated, ready)

- **LLM post classification** (`LlmPostClassifier`, Anthropic Haiku):
  post_type (dd/technical/hype/news/question/other), direction, conviction,
  pump_suspicion, catalyst claim → `post_sentiments.llm_*`. Live dispatch
  from `ProcessRawPost` (ticker-mentioning posts, ≥40 chars, daily cap 500);
  historical via `pennyhunt:classify-posts`, which targets ONLY posts on
  backtest candidate (ticker, day)s — classifying the full archive would
  cost 20–50× more for no training benefit.
- **Author track records** (`ScoreAuthorTrackRecords`, nightly 06:30):
  Laplace-smoothed hit rate over graded candidate days the author posted
  into, ≥3 graded mentions → `authors.track_record_score` / `track_record_n`.
  Not yet a model feature (needs as-of computation inside the backtester —
  phase B follow-up); currently for surfacing and manual review.

## Methodology notes

- **No new live gates.** The new features feed the confidence model only.
  Whether e.g. `atm_filed_90d` deserves a hard veto is a question the next
  backtest answers with weights, not one we prejudge.
- **Winner profile extended** with median short ratio, share growth, ATM/shelf
  rates for fired hits vs misses — the univariate sanity check.
- **Unknown stays unknown**: missing short-volume/EDGAR coverage yields null
  features persisted as null on events; the standardized regression treats
  them as mean-value (neutral), not as fake zeros with meaning.

## Verification

- 94 tests pass (13 new): as-of/no-look-ahead guards for every feature
  (filing look-ahead, ATM window expiry, share-growth pairing, short-ratio
  staleness, benchmark momentum, site-z coverage floor), Reg SHO parsing +
  facility summing + universe filtering + idempotent day skipping, EDGAR
  sync (form filtering, share counts, sync stamps), LLM classifier
  (persistence, fence tolerance, clamping, key gating), author track records
  (smoothing, dedupe, minimum-sample floor).
- Pint / ESLint / tsc clean.

## Data status (at time of writing)

- EDGAR backfill running: 4,311 mentioned tickers (~1.5–2h at SEC rate limit).
- Reg SHO backfill running: 730 days (~150k rows stored in first minutes).
- IWM benchmark bars synced: 542 daily bars (26 months).
- 2025 H2 Reddit backfill still running (Arctic Shift).

## Next

1. When backfills land: re-run the flagship backtest (12-feature trainer,
   two regimes), inspect new-feature weights + winner profile deltas.
2. Sign-ups (see README "What to sign up / pay for"): Anthropic key (phase B
   activation), Alpaca free account (phase D prep). Polygon Starter ($29/mo)
   deferred until phase D.
3. Phase B follow-up: as-of author track record inside the backtester.
4. Phase C: gradient boosting + isotonic calibration (Python sidecar).
