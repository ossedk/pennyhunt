# Pennyhunt — Master Plan

> Last updated: 2026-07-04
> Status: **Phases 0–1 built and verified**, plus lexicon sentiment, rollups and
> signal engine v1 (early Phase 2/3). See `docs/ARCHITECTURE.md` for what exists
> and `docs/DATA_SOURCES.md` for source status. **Reddit ingestion is LIVE via
> Apify** (reddit-scraper-lite, batched run every 15 min, posts flowing since
> 2026-07-02). Native Reddit OAuth remains as a free fallback path. Tradestie is
> Cloudflare-blocked and seeded disabled; ApeWisdom + SEC ticker sync verified live.

## 1. Mission

Pennyhunt ingests penny-stock and momentum-stock chatter from Reddit (r/pennystocks, r/wallstreetbets, r/Shortsqueeze, r/smallstreetbets, etc.), X, and similar communities, converts it into per-ticker sentiment and attention signals, and answers one question with statistical honesty:

**Can retail-forum sentiment predict outsized price moves before they happen?**

Everything else — the dashboard, alerts, tooling — exists to (a) validate that hypothesis with a backtest engine, and (b) let us act on the signal in real time if it holds.

### Success criteria (what "winning" means)

- A composite signal (mention velocity + sentiment + author quality + market confirmation) that, backtested over ≥ 12 months of history, identifies tickers that go on to move ≥ +30% within 5 trading days, with precision meaningfully above the base rate and a measurable **lead time** (signal fires before the move, not during it).
- Honest measurement: walk-forward evaluation, no lookahead bias, survivorship-bias-free ticker universe, and per-signal PnL simulation including realistic penny-stock spreads/slippage.
- If the signal does NOT predict (only reacts), the platform must tell us that clearly — that is also a valid, valuable answer.

### Known hard truths (design around these)

1. Academic and practitioner research consistently shows forum mentions often **lag** price (the pump starts, then the forum lights up). Our edge, if any, lives in *early-phase* detection: acceleration of mentions from low baselines, first-mover authors with good track records, and small-cap tickers before they hit the front page.
2. Penny stock forums are full of **coordinated pumps and bots**. Author-level features (account age, karma, posting history, cross-post detection) are not optional — they are the core of signal quality.
3. Ticker extraction on forums is genuinely hard ("CEO", "DD", "YOLO", "ALL" are all valid tickers). This needs a real disambiguation layer, not a regex.

---

## 2. Five-paragraph tradeoff analysis

**Monolith vs. distributed ingestion.** The tempting design is a fleet of microservices per source (Reddit worker, X worker, NLP service, signal engine) communicating over a queue. At our actual scale — tens of thousands of posts/comments per day across ~20 subreddits, not millions — that is premature. A Laravel monolith with Horizon-managed queue workers handles this comfortably: each source is a scheduled/streaming job class, each pipeline stage is a queued job, and Redis is the backbone. The one justified exception is NLP inference: PHP cannot run FinBERT-class transformer models, so sentiment scoring lives in a small Python sidecar (FastAPI) or is delegated to hosted LLM APIs. We choose **Laravel monolith + one Python NLP sidecar**, which keeps operational surface tiny while isolating the only component with a genuinely different runtime.

**Buy vs. build data ingestion.** Reddit's commercial API tier has a ~$12k/yr floor and a 2–4 week approval process; X's pay-per-use API costs ~$0.005/post read (a naive WSB firehose would burn thousands of dollars monthly). Meanwhile pre-aggregated providers (ApeWisdom — free, mentions only; Tradestie — free WSB sentiment; Quiver Quantitative — $30/mo WSB sentiment; Adanos — scored multi-subreddit sentiment from $29/mo) exist precisely for this. But aggregators only give us *daily/hourly ticker-level rollups* — no post text, no author metadata, no comment velocity, which are exactly where the predictive edge lives. The tradeoff: **Phase 1 uses Reddit's free OAuth tier (100 req/min is ample for ~20 subreddits polled every 1–2 min) plus free aggregators for backfill and cross-validation; we defer X to a third-party provider (e.g. twitterapi.io at ~$0.15/1k tweets) rather than X's native API; we budget for Reddit commercial licensing only once the strategy is proven** and the project becomes commercial. This keeps burn near zero during the validation phase, which is when most such projects die.

**Real-time architecture: polling vs. streaming vs. hybrid.** True streaming (Reddit doesn't offer a firehose to normal developers anyway) is unavailable; the realistic options are tight polling loops feeding a queue, or lazy batch pulls every N minutes. Penny-stock pumps develop over minutes-to-hours, not milliseconds, so a 60–120 second polling cadence per subreddit is genuinely "real time" for this domain. We run **Horizon workers on a 1–2 minute poll cadence**, normalize and dedupe into Postgres, and push increments to the UI over **Laravel Reverb** (first-party WebSocket server, Redis pub/sub scaling if we ever need multi-node). This gives users a live-updating dashboard without the cost and fragility of pretending we need sub-second latency.

**Storage: Postgres vs. Postgres+Timescale vs. ClickHouse.** The workload is two-sided: an OLTP side (posts, authors, tickers, users, alerts) and a time-series side (per-ticker minute/hour buckets of mentions, sentiment, price, volume). ClickHouse would be the "correct" analytical answer at 100× our volume but adds a second database to operate. TimescaleDB is attractive but complicates managed hosting. At validation-phase scale, **plain PostgreSQL 16+ with well-designed aggregate tables** (pre-computed 5-min/1-hour/1-day rollups maintained by queue jobs, BRIN indexes on time columns, partitioning on the raw posts table by month) is sufficient and keeps us on one database. We explicitly reserve the right to bolt on ClickHouse later purely for the backtest/analytics workload; the schema is designed so raw events can be replayed into it.

**Sentiment: lexicon vs. FinBERT vs. LLM vs. hybrid.** Current research (2025–2026) is unambiguous: FinBERT alone detects tone, not price impact, and is wrong on the majority of "positive" calls; large LLMs reason far better but cost ~$2–3 per 1,000 texts; and forum slang ("🚀", "diamond hands", "bagholding") defeats models trained on news. The proven architecture is **tiered triage**: (1) a cheap fast filter (FinBERT or a slang-aware fine-tune) discards the ~60–65% of posts that are noise/neutral, (2) only escalated posts go to an LLM (Claude/GPT class) with a finance-and-WSB-aware prompt that outputs structured JSON (direction, conviction, is_pump_suspicion, tickers). This matches pure-LLM signal quality at ~20% of the cost. We additionally keep a dumb-but-fast lexicon score (VADER + custom WSB lexicon) computed on *everything*, because backtests need a full-coverage baseline signal to compare against. All three scores are stored per post so we can evaluate which one actually predicts.

---

## 3. System architecture

```
                        ┌─────────────────────────────────────────────┐
                        │                LARAVEL 13 APP                │
                        │                                             │
  Reddit API  ──poll──▶ │  Ingestion Jobs (Horizon queues)            │
  ApeWisdom   ──poll──▶ │   └─ normalize → dedupe → store raw post    │
  Tradestie   ──poll──▶ │                                             │
  twitterapi.io ─poll─▶ │  NLP Pipeline (queued, per post)            │──HTTP──▶ Python NLP
  Market data ──poll──▶ │   └─ ticker extraction → sentiment request  │◀─JSON──  sidecar
  (FMP/Massive)         │                                             │  (FinBERT triage
                        │  Signal Engine (scheduled, per ticker)      │   + LLM escalation)
                        │   └─ rollups → z-scores → composite score   │
                        │   └─ anomaly detection → alert dispatch     │
                        │                                             │
                        │  Backtest Engine (on-demand, historical)    │
                        │                                             │
                        │  Reverb (WebSockets) ──▶ live dashboard     │
                        └─────────────────────────────────────────────┘
                              │                    │
                        PostgreSQL 16          Redis (queues,
                        (raw + rollups)        cache, pub/sub)
```

### Stack (verified current, July 2026)

| Layer | Choice | Why |
|---|---|---|
| Backend | **Laravel 13** (PHP 8.3+) | Current major (released 2026-03-17), bug fixes to Q3 2027 |
| Frontend | **Official React starter kit**: Inertia + React 19 + TypeScript + Tailwind 4 + shadcn/ui | This is the official, documented way to get shadcn/ui in Laravel (`laravel new pennyhunt --react`) |
| Real-time | **Laravel Reverb** + Echo | First-party WebSocket server; Redis pub/sub if scaling |
| Queues | **Laravel Horizon** + Redis | Dashboard, retry policies, per-queue balancing |
| Database | **PostgreSQL 16+** | Partitioned raw tables + rollup tables |
| NLP sidecar | **Python 3.12 + FastAPI** | FinBERT/transformers + LLM API orchestration |
| Charts | **TradingView Lightweight Charts** (free, OSS) for price; Recharts for stats | Best-in-class candlestick UX |
| Scheduler | Laravel scheduler (`schedule:work` / cron) | Polling cadence control |
| Monitoring | Laravel Pulse + Horizon dashboard + Sentry | Already have Sentry available |

### Data sources & realistic costs (verified July 2026)

| Source | What we get | Cost | Phase |
|---|---|---|---|
| Reddit Data API (free OAuth, non-commercial) | Full posts + comments + author metadata, 100 req/min | $0 (validation phase) | 1 |
| ApeWisdom API | Ticker mention counts across ~15 subs + 4chan /biz, no key | $0 | 1 |
| Tradestie WSB API | Top-50 WSB tickers w/ sentiment, no key | $0 | 1 |
| Quiver Quantitative | WSB mentions + sentiment, nightly, historical | $30/mo | 2 (backfill/cross-check) |
| twitterapi.io (or similar 3rd-party X data) | Cashtag search on X, ~$0.15/1k tweets | ~$20–100/mo | 2 |
| Stocktwits | API frozen to new devs — **skip** unless enterprise deal | — | — |
| Market data: FMP ($19–29/mo) or Massive/Polygon ($199/mo real-time) | Quotes, bars, volume; OTC data is 15-min delayed everywhere retail | $19–199/mo | 1 |
| LLM API (Anthropic/OpenAI) | Sentiment escalation tier | ~$0.10–0.50/day per 200 escalated posts; budget $50–150/mo | 2 |
| Reddit commercial license | Required if/when Pennyhunt is monetized | ~$12k/yr floor — only after strategy proven | 4+ |

**Compliance note:** Reddit free tier is for non-commercial use. Validation/research phase qualifies; the moment Pennyhunt charges money or feeds a monetized product, we must either sign Reddit's commercial agreement or switch fully to licensed aggregators. This is a hard gate tracked in the roadmap.

---

## 4. Data model (core tables)

- `sources` — reddit_sub / x / forum, with polling config per source.
- `raw_posts` — partitioned by month. Post/comment id, source, author_id, title, body, score, num_comments, created_at (source time), ingested_at. Unique on (source, external_id).
- `authors` — account age, karma, post history stats, **pump_risk_score** (updated nightly), track record (did their past picks move?).
- `tickers` — symbol, exchange, tier (NYSE/Nasdaq/OTC-Pink/etc.), float, market cap, is_active; refreshed from market-data provider. Includes an **ambiguity flag** for word-collision symbols (CEO, DD, ALL, IT…).
- `post_ticker_mentions` — post ↔ ticker with extraction confidence (cashtag beats bare word; context-validated).
- `post_sentiments` — one row per post per model: lexicon_score, finbert_label+score, llm_direction/conviction/pump_suspicion (nullable until escalated). Keeping all models side-by-side is what makes the backtest able to say *which* signal predicts.
- `ticker_metrics_5m / _1h / _1d` — rollups: mention_count, unique_authors, weighted_sentiment, author_quality_avg, vs-baseline z-scores.
- `market_bars` — OHLCV per ticker per interval (1m where affordable, else 5m/1d).
- `signals` — fired composite signals: ticker, fired_at, score breakdown (JSON), state (new/confirmed/expired), later annotated with realized forward returns (t+1d, t+3d, t+5d) for continuous self-evaluation.
- `alerts`, `watchlists`, `users` — platform layer.

---

## 5. The signal engine

### Stage 1 — Ticker extraction
1. Cashtags (`$ABCD`) accepted with high confidence.
2. Bare uppercase symbols validated against active ticker universe; ambiguous symbols require contextual confirmation (finance context words nearby, subreddit prior, or LLM check on escalation).
3. Company-name matching (fuzzy) as a low-confidence third tier.

### Stage 2 — Sentiment (tiered, per §2 ¶5)
- Tier 0 (all posts): VADER + custom WSB/pennystock lexicon → fast baseline score.
- Tier 1 (all posts): FinBERT (ProsusAI or a slang-tuned variant) in the Python sidecar → triage. Neutral = stop.
- Tier 2 (non-neutral only, ~35%): LLM with structured output: `{direction, conviction 0–1, pump_suspicion 0–1, reasoning}`.

### Stage 3 — Attention & anomaly features (per ticker, rolling)
- Mention velocity (5m/1h) and **acceleration** vs. that ticker's own 30-day baseline (z-score). A ticker going 0→15 mentions/hour matters far more than GME going 500→600.
- Unique-author breadth vs. single-account spam (Gini / concentration of mentions per author).
- Author quality: weighted by account age, karma, historical hit rate; penalized by pump_risk_score.
- Cross-source confirmation: same ticker rising on ≥2 independent sources within a window.
- Market confirmation: unusual volume z-score, price still within X% of pre-buzz level (i.e., "buzz is early, move hasn't happened yet" — this is the money condition).

### Stage 4 — Composite score & alerting
- Weighted composite (weights initially heuristic, later fit on backtest data — logistic regression / gradient boosting over the labeled signal history).
- Thresholded → `signals` row → broadcast over Reverb → in-app + push/email/Telegram alert.
- Every signal is auto-graded days later with realized forward returns. The platform continuously reports its own precision.

### Stage 5 — Backtest engine (the heart of the project)
- Replay historical data (Quiver backfill + our own accumulating archive + optional academic Reddit dumps) through the exact same pipeline.
- Walk-forward: train weights on months 1–6, evaluate on month 7, roll.
- Metrics: precision@k, hit rate for ≥+30%/5d moves, average lead time (signal→move start), simulated PnL with penny-stock-realistic assumptions (wide spreads, limited liquidity, no shorting availability).
- Explicit "reaction vs. prediction" report: what fraction of signals fired before vs. after the move began.

---

## 6. Platform & UX

Dark theme by default (shadcn/ui `dark` class strategy, near-black background `#09090b`, high-contrast data colors: green/red for direction, amber for pump-risk). Left sidebar navigation (shadcn `Sidebar` component, collapsible). Dense but calm data UI — think trading terminal, not marketing site.

### Navigation (left sidebar)
1. **Radar** (home) — live composite-score leaderboard: tickers ranked by signal strength right now, sparklines of mention velocity, sentiment gauge, price + volume confirmation chips, one-click to detail. Real-time via Reverb.
2. **Ticker detail** — mention/sentiment timeline overlaid on candlestick price chart (Lightweight Charts), top posts driving the buzz (with author quality badges and pump-risk flags), source breakdown, historical signals for this ticker and how they resolved.
3. **Feed** — live normalized stream of ingested posts, filterable by source/ticker/sentiment/author quality. The "watch the tape" view.
4. **Signals** — every fired signal, its score breakdown, current state, and realized outcome. This doubles as the public track record of the system.
5. **Backtests** — configure and run backtests, view equity curves, precision/lead-time reports, weight tuning results.
6. **Watchlists & Alerts** — user watchlists, alert rules (composite threshold, specific ticker, source spike), delivery channels.
7. **Sources** — ingestion health: per-source poll status, lag, volume, error rates (ops view).
8. **Settings** — profile, appearance, API keys, alert channels.

### UX principles
- Every number answers "compared to what?" — z-scores and baselines shown next to raw counts.
- Pump-risk is always visible next to bullish sentiment (amber badge) — the UI must never present a likely pump as a clean buy signal.
- Signal → evidence in one click: from any score to the actual posts that caused it.
- Latency honesty: every panel shows data freshness ("Reddit: 40s ago", "OTC price: 15m delayed").

---

## 7. Roadmap

### Phase 0 — Foundation (week 1) ✅ DONE 2026-07-02
- `laravel new pennyhunt --react` (Laravel 13, React 19, Inertia, Tailwind 4, shadcn/ui), dark theme, left sidebar shell, auth.
- Postgres, Redis, Horizon, Reverb, Pulse wired up. (Sentry + Docker compose deferred; local brew services in use.)
- Tests: Pest suite green (53 tests); Pint, ESLint, tsc all clean.

### Phase 1 — Ingestion & archive (weeks 2–3) ✅ BUILT & LIVE 2026-07-02
- **Reddit ingestion LIVE via Apify** (`trudax/reddit-scraper-lite`, pay-per-result):
  one batched run / 15 min for all 15 subreddits, incremental via `postDateLimit`,
  ~$1–5/day posts-only. Comments and per-post vote detail deliberately off for
  cost (see DATA_SOURCES.md). Native OAuth pollers kept as free fallback
  (activate by clearing `APIFY_KEY` and setting REDDIT_CLIENT_ID/SECRET).
- ApeWisdom poller working (verified live); Tradestie blocked by Cloudflare, seeded disabled.
- Ticker universe synced from SEC (10,426 symbols, free); FMP enrichment ready behind `FMP_API_KEY`.
- Ticker extraction v1 (cashtags + validated symbols + 62-symbol ambiguity list) with unit tests.
- Feed page + Sources health page live; backtest history is accumulating as of 2026-07-02.

### Phase 2 — Sentiment & metrics (weeks 4–5) — PARTIALLY DONE
- ✅ Lexicon (tier 0) scoring with WSB dialect + emoji + negation, unit-tested.
- ✅ Rollup jobs (5m/1h/1d), 30-day baselines and z-scores (SQL upserts).
- ✅ Ticker detail page with mention/sentiment chart (price overlay pending market data key).
- ⬜ Python NLP sidecar: FinBERT triage + LLM escalation (schema columns already
  in place). **Priority raised by backtest v2: lexicon sentiment's fitted weight
  is ≈ 0 — better sentiment is the clearest open lever.**
- ✅ ~~Quiver subscription~~ replaced by **Arctic Shift backfill (free)** — done 2026-07-03.

### Phase 3 — Signal engine & real-time UX (weeks 6–7) — PARTIALLY DONE
- ✅ Composite scoring v1 (heuristic weights, full breakdown stored per signal), cooldowns, auto-grading job (via Yahoo bars, no key needed).
- ✅ Radar leaderboard + Signals page, live over Reverb.
- ✅ Author quality weighting v1 (age + karma, pump-risk scaled).
- ✅ Alert rules + delivery — `EvaluateAlertRules` listener on SignalFired
  (composite_threshold / ticker_signal / mention_spike → in-app alert_events,
  optional mail). Rule-management UI still pending.
- ✅ Pump-risk scoring job (`ScoreAuthorPumpRisk`, nightly 04:30) — ticker
  concentration + posting burst + account newness → authors.pump_risk_score.

### Phase 4 — Backtesting & validation (weeks 8–10) — IN PROGRESS 2026-07-03
- ✅ Historical backfill: 6 months × 15 subreddits via Arctic Shift (free), replayed
  through the production ingestion pipeline (`pennyhunt:backfill-reddit`).
- ✅ Market bars: keyless Yahoo daily OHLC → `market_bars` (`pennyhunt:sync-market-bars`).
- ✅ Backtest engine v1 (`Backtester` + `RunBacktest` + /backtests UI): as-of rolling
  baselines (no look-ahead), SignalMath shared with the live engine, next-day-open
  entries, +1/+3/+5d returns, control group base rate, reaction-vs-prediction split.
- ✅ First validation run (v1, superseded): `docs/audits/2026-07-03-backtest-v1.md`.
  **v1's headline edge was largely a split-adjustment artifact** — Yahoo doesn't
  retro-adjust many small-cap series; fixed in YahooMarketData, bars re-synced.
- ✅ Backtest v2: `backtest_events` table (every scored candidate, fired + control),
  volume z / dollar-volume / price features, friction-adjusted net PnL,
  market-confirmation gates, winner-profile analysis, paginated signals UI.
- ✅ Walk-forward weight fitting (`pennyhunt:fit-weights`): out-of-sample
  precision@25 = 16% vs 4.3% base (3.8x lift). Sentiment weight ≈ 0; breadth
  negative; small-cap + momentum + volume dominate.
- ✅ PnL simulation with frictions + gating experiments (v2 audit): best config
  (price ≤ $5 + vol z ≥ 2) hits 22.1% (6.9x base) but nets ~breakeven at 5%
  friction with naive 5-day exits.
- ✅ Exit-rule experiments (stop/take asymmetry) — v3 audit. Winner: **10% stop,
  NO take-profit** → +1.39% net/trade, PF 1.13 (vs −1.15% time-only). Every
  take-profit variant loses money: the expectancy lives in the uncapped tail.
- ✅ Price-cap + volume gates applied to the live SignalEngine
  (`pennyhunt.signals.market_gate`: close ≤ $5, volume z ≥ 2, on-demand bar
  sync, gate verdict stored in signal breakdown). Daily bar sync scheduled 05:00.
- ✅ Confidence pipeline (v4): walk-forward P(hit) on every backtest event
  (monthly refits, no look-ahead, auto-run per backtest), persisted live
  model (`signal_models` + `pennyhunt:train-confidence`), live signals scored
  at fire time (`signals.confidence`). Calibration first-class: top quintile
  9.1% predicted vs 9.6% realized (~6x bottom quintile), but Brier ≈
  base-rate — confidence ranks, its level is not gospel.
- ✅ Kelly portfolio simulation (v4): equity-curve replay comparing equal
  weight vs half/full Kelly (as-of realized p and b per confidence tercile),
  capped at 10% equity + 1% of signal-day dollar volume. Run #30: full Kelly
  +13.4% with 12.9% max DD vs equal weight +7.6% with 34.0% DD (38 trades —
  small sample). `docs/audits/2026-07-03-confidence-kelly.md`.
- ✅ Post-signal price charts (signals page expandable rows with entry/stop/
  time-exit annotations; ticker page 6-month price + signal markers).
- ✅ **Signal-quality phase A (2026-07-03)** — point-in-time feature expansion,
  all free/keyless sources, shared between backtester and live engine via
  `MarketIntelligence`:
  - **Dilution (SEC EDGAR)**: `sec_filings` (S-3/F-3 shelves, 424B takedowns,
    8-K/10-K/10-Q) + `ticker_share_counts` (XBRL cover-page shares) →
    `active_shelf`, `atm_filed_90d`, `share_growth_12m` as-of any day.
    `pennyhunt:sync-sec-filings`, nightly 05:30.
  - **Short flow (FINRA Reg SHO)**: `short_volumes` daily short/total ratio
    (CNMS + CORF files, keyless) → `short_ratio`. `pennyhunt:sync-short-volume`,
    nightly 05:15; 24-month backfill supported.
  - **Regime**: IWM 5-session momentum (`market_ret_5d`, benchmark bars ride
    along with the nightly bar sync) + site-wide mention z (`site_mention_z`).
  - All six features persisted on `backtest_events`, added to
    `ConfidenceTrainer::FEATURES` (12 features total, assoc-array builder),
    scored live in `SignalEngine` and stored in the signal breakdown.
- ✅ **Signal-quality phase B infrastructure (2026-07-03)**:
  - LLM post classification (OpenAI gpt-5-mini preferred / Anthropic Haiku
    fallback, key-gated, daily spend cap): post_type (dd/technical/hype/news)
    + direction + conviction + pump_suspicion + catalyst claim →
    `post_sentiments`. Live dispatch from the pipeline + targeted historical
    `pennyhunt:classify-posts` (only candidate-day posts — cost-bounded).
    **Active since 2026-07-03** (`OPENAI_API_KEY` set); 79k-post historical
    backfill of run #31 candidate days running.
  - Author track records (`ScoreAuthorTrackRecords`, nightly 06:30):
    Laplace-smoothed hit rate over backtest candidate days the author posted
    into → `authors.track_record_score` / `track_record_n`.
- ✅ Second window (2025 H2) regime-robustness check — **run #31, edge does
  NOT survive**: full 12 months nets −2.88%/trade (PF 0.74); 2025 H2 alone
  −5.80%/trade; even 2026 H1 shrinks to −0.14% once baselines carry real
  history (part of the earlier +1.70% was warm-up artifact). Kelly sizing
  correctly refused 86% of trades (−5.5% vs −43% equal-weight). Confidence
  ranking IS real (q5 8.0% vs q1 1.8% realized, monotone) and the activated
  12-feature model found atm_filed_90d (+0.20) the second-strongest
  predictor. `docs/audits/2026-07-03-two-regime-flagship.md`.
- ⬜ Live forward test of the gated engine against the v3 trade discipline
  (enter next open, 10% stop, no take, time-exit day 5) — now scored by the
  live 12-feature model to accumulate ranked evidence.
- ✅ Top-confidence-only policy simulation — hit rate rises monotonically
  with confidence (17.5% → 26.7%) confirming ranking skill, but net/trade
  flips sign between adjacent thresholds (p75 +0.45%, p90 −3.90%) and no
  tier survives 2025 H2: small-sample tail dominance, not a tradable rule.
  Confidence sizes and ranks; it doesn't rescue the configuration.
  (Addendum in the two-regime audit.)
- ✅ **Macro + momentum features (2026-07-03)** — three more point-in-time
  features via `MarketIntelligence` (15 total): `vix` (Yahoo ^VIX, ≤6d
  stale), `btc_ret_5d` (retail risk-appetite proxy), `mention_streak`
  (consecutive rising-mention days — explicit momentum-continuation
  measure). Backfilled onto run #31, model retrained + activated
  (v2026-07-03-run31.3). Macro answer: VIX ≥ 25 is the worst P&L bucket
  (−11.5%/trade) and site-wide frenzy (z ≥ 1.5) halves the hit rate —
  macro explains the loss tail, not the whole invalidation.
  `docs/audits/2026-07-03-macro-momentum-fundamentals.md`.
- ✅ **Company fundamentals + stock page (2026-07-03)** — Polygon Stocks
  Starter live (`POLYGON_API_KEY`): `ticker_profiles` + `ticker_financials`
  (SEC XBRL quarterly), lazy `SyncCompanyProfile` on page view. Ticker page
  now shows company profile, quarterly accounting table, dilution/short-flow
  snapshot, and a verified-Twitter panel (populates once the paid Apify
  Twitter poller is enabled).
- ✅ Archive extension to 24 months **done 2026-07-03** (2024-07 → 2025-07,
  Arctic Shift): +383,838 historical posts, all pipeline-processed; archive
  now spans 2024-07-03 → today (744,939 raw posts). Unblocks a 24-month
  backtest (third regime, ~2× events) — market bars for the new window sync
  on demand during the run.
- ✅ **24-month flagship backtest (run #32, 2026-07-03)** — third regime
  confirms run #31: hit rate 13.1% vs 3.9% base (3.4× lift), confidence
  quintiles monotone (q5/q1 = 5.3× realized), but net −4.51%/trade at 5%
  friction (PF 0.59, 64% stop rate). Equal weight −80% over 24 months;
  Kelly refused 97% of trades and preserved capital (−3.6%). Ranking skill
  is regime-stable; the configuration is not tradable. No warm-up artifact
  (window starts 30 days after archive). Data prep fixed a Yahoo
  reverse-split overflow bug (prices >10^9 dropped, 14 tickers recovered).
  `docs/audits/2026-07-03-backtest-24m-run32.md`.
- ✅ **Regime kill switches tested on run #32 (2026-07-04)** — mostly dead:
  the run #31 "VIX ≥ 25" kill did NOT replicate (killed bucket PF 0.92 vs
  kept 0.57 on 24 months — regime-specific noise); site-buzz z ≥ 1.5 is
  directionally real (killed bucket PF 0.44) but lifts the kept book only
  0.59 → 0.63. Macro belongs inside the model as features, not as gates.
  `scripts/regime_kill_switch_analysis.php`.
- ✅ **Phase C non-linear model (2026-07-04)** — walk-forward
  HistGradientBoosting + isotonic calibration over run #32 (31,768 events
  scored out-of-sample, same monthly-refit protocol as ConfidenceTrainer):
  **first model to beat the base-rate Brier** (0.03832 vs ref 0.03936;
  logistic 0.03972) with 24× top/bottom decile separation (13.1% vs 0.5%
  realized) vs 5× for logistic. Causal top-tier policies go positive for
  the first time — GBM p ≥ 0.15: +2.7% net/trade (n=144, PF 1.23);
  expanding-p75: +4.1% (n=145, PF 1.35) — but bootstrap CI90 still spans
  zero and 2025-H1 is negative for every tier. Promising, not deployable.
  `docs/audits/2026-07-04-phase-c-gbm.md`, `scripts/phase_c_gbm.py`,
  `scripts/phase_c_robustness.py` (.venv-ml, scikit-learn 1.9).
- ✅ **GBM productionized + ACTIVE (2026-07-04)** — `pennyhunt:train-gbm`
  exports events → trains in Python (`scripts/train_gbm_model.py`,
  isotonic layer fitted on out-of-sample scores only) → imports a JSON
  artifact evaluated in **pure PHP** at fire time (~10ms; no Python on the
  live path; parity vectors verified at import). Active model
  `gbm-v2026-07-04-run32.4`: calibrated walk-forward Brier 0.03774 (ref
  0.03936), near-perfect decile reliability. Research trade tier ↔
  calibrated p ≥ 0.124. Unit tests: `tests/Unit/SignalModelGbmTest.php`.
- ✅ **LLM aggregate features wired end-to-end (2026-07-04)** — new
  `LlmAggregates` feature service (same shared-instance pattern as
  MarketIntelligence: one definition for backtester AND live engine):
  8 per-(ticker, day) features — `llm_coverage` (share of the day's
  mention posts with an LLM verdict, so the model can discount thin
  days), `llm_direction`, `llm_conviction`, `llm_pump_suspicion`,
  `llm_dd_share` / `llm_hype_share` / `llm_news_share`,
  `llm_catalyst_share`. Persisted on `backtest_events`, in
  `ConfidenceTrainer::FEATURES` (23 total), scored live in SignalEngine
  (breakdown.llm), exported to the Python trainer.
  `pennyhunt:backfill-llm-features --run=N` recomputes them onto an
  existing run without re-backtesting (idempotent — re-run as the
  classification backfill progresses). Verified end-to-end with a
  23-feature retrain (run32.5, kept inactive: only 16.7% of events have
  coverage yet, metrics unchanged vs run32.4 as expected).
- ⬜ Retrain + activate the 23-feature GBM when the run #32 candidate-day
  classification backfill (~147k posts total; 13.4k classified, ~133k
  remaining as of 2026-07-04 17:00) reaches high coverage:
  `pennyhunt:backfill-llm-features --run=32` →
  `pennyhunt:train-gbm --run=32 --activate`. Then author track records
  as a feature; expected-net-exit-return label experiment.
  **Decision 2026-07-04: the classification backfill moves to a server**
  (local runs kept dying with the laptop/IDE sessions). The command is
  idempotent and restartable — already-classified posts are skipped on
  re-invocation, so run on the server under nohup/systemd/supervisor:
  `php artisan pennyhunt:classify-posts --run=32 --limit=140000`.
  At ~2.5 posts/s (rate-limit sleep) the remainder is ~15h of runtime;
  requires `OPENAI_API_KEY` + `PENNYHUNT_LLM_MAX_PER_DAY` sized to match.
  **Nightly shadow retrain scheduled (07:00/07:15, NO auto-activation)**
  — feature refresh + inactive GBM import daily, so LLM-feature
  importance is observable as coverage climbs (`storage/logs/ml-nightly.log`).
- ✅ **Trade Cockpit SHIPPED (2026-07-04)** — all three phases of
  `docs/plans/2026-07-04-trade-cockpit.md`:
  - **Trade engine**: `signal_trades` ledger + `TradeEngine` (auto-opens a
    paper trade for every signal ≥ the active model's calibrated trade
    tier via `OpenTradeForSignal` listener; fills entries at the next
    session's open and walks stop/day-5 exits with the exact
    `Backtester::simulateExit` pessimistic OHLC rules). Jobs:
    `ManageSignalTrades` (05:10 daily, after bar sync) +
    `RefreshOpenTradeQuotes` (15-min indicative quotes, US market hours;
    never closes trades — exits are authoritative on daily bars only).
    Half-Kelly size suggestion from the model's own run payoff ratio.
    Reverb channel `pennyhunt.trades` (`trade.updated`).
  - **Signal cockpit** (`/signals/{id}`): trade-plan card, candle chart
    with entry/stop levels + fire/exit markers, decision-evidence
    checklist vs run #32 winner/loser medians, historical-analog stats
    (same price bucket + volume band), regime + dilution rails,
    mention-momentum bars, LLM-badged social tape.
  - **Blotter** (`/signals`): forward-test scoreboard, Positions /
    History / Signal-log tabs, position risk-alert chips, live refresh.
  - **Radar**: RegimeBanner (VIX/S&P/BTC/site-buzz), live composite +
    "forming" rows on the leaderboard, tier-badged recent signals,
    open-positions rail.
  - **Feed**: LLM post-type badges + direction/conviction, post-type
    filter, "My positions" filter, off-topic posts excluded.
  - **Trade alerts** (`TradeAlerts`, system-generated `alert_events`):
    stop proximity (≤3%), time-exit-next-session, dilution filing since
    entry, mention collapse (>70% drop from fire day). Deduped per
    trade+kind+day.
  - Tests: `TradeEngineTest` (7), `TradeAlertsTest` (4),
    `SignalCockpitPageTest` (2); full suite green (126).
- ▶️ Forward paper-trade of the calibrated p ≥ 0.124 tier (v3 discipline:
  next-open entry, 10% stop, no take, day-5 time exit) — **now running
  automatically via the trade engine**; every future trade-tier signal
  becomes a managed paper position. Needs ≥50 closed trades before the
  scoreboard is trusted as gate evidence.
- ⬜ Phase D (execution realism): minute-bar stop-fill validation via Alpaca
  (free account) or Polygon Starter ($29/mo); paper-trading forward test.
- **Decision gate:** does any signal configuration show predictive lead time and precision above base rate? **Status: NOT passed, but Phase C moved it for the first time.** The trade-everything configuration is invalidated across three regimes (run #32: −4.51%/trade, PF 0.59). What survives and is regime-stable: 3.4–4.4× precision lift, monotone confidence ranking, Kelly risk control. **New (2026-07-04): the walk-forward GBM is the first model to beat the base-rate Brier, and its causal top tier (p ≥ 0.15) shows +2.7–4.1% net/trade over 24 months — positive expectancy visible for the first time, though bootstrap CIs still span zero and 2025-H1 stays negative.** The gate now needs: GBM in production, LLM post-type + author features added, and a forward paper-trade of the top tier. Analyses: `docs/audits/2026-07-04-phase-c-gbm.md` (latest), `docs/audits/2026-07-03-backtest-24m-run32.md`, `docs/audits/2026-07-03-two-regime-flagship.md`.**

### Phase 5 — Act & scale (only if Phase 4 gate passes)
- ✅ **X/Twitter ingestion LIVE (2026-07-03)** — `PENNYHUNT_TWITTER_ENABLED=true`
  on the existing `APIFY_KEY`; first run ingested 300 tweets / 132 verified
  authors; hourly schedule active; ticker-page verified-voices panel populated.
- ✅ **Twitter quality gates (2026-07-03)** — three layers: (1) `min_faves:5`
  in the search query (don't pay for bot junk) + ingest-side like floor;
  (2) `TweetSpamScanner` heuristics — crypto airdrop/wallet/presale promos
  colliding with stock cashtags, contract addresses, cashtag stuffing (>6),
  telegram funnels — word-boundary matched with equity-context rescue;
  (3) LLM `off_topic` verdict (new `post_sentiments.llm_off_topic`) deletes
  ticker mentions on off-topic tweets so crypto collisions can't poison
  mention velocity. Retroactive purge of the unfiltered first run: 300 → 18
  tweets survived (279 low-likes, 3 spam).
- ✅ (pulled forward) X/Twitter ingestion built via Apify `apidojo/twitter-scraper-lite`:
  targeted cashtag confirmation on Reddit-trending tickers, hourly, cost-bounded
  (~$1–2.50/day). Disabled by default (`PENNYHUNT_TWITTER_ENABLED`) — requires a
  paid Apify plan. See DATA_SOURCES.md.
- More forums (Discord servers, Investorshub if licensable).
- Reddit commercial licensing conversation (required before monetization).
- Paper-trading integration (Alpaca) to forward-test signals with real fills.
- Multi-user/commercial hardening if we productize.

---

## 8. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Sentiment lags price (reaction not prediction) | Acceleration-from-low-baseline focus; lead-time measurement is a first-class metric; decision gate at Phase 4 |
| Coordinated pumps poison the signal | Author quality + concentration features; pump_suspicion from LLM; amber-flag UX |
| Reddit ToS (non-commercial free tier) | Validation phase is non-commercial research; commercial license or licensed aggregators before monetization |
| Ticker false positives (CEO, DD…) | Ambiguity list + contextual validation + confidence scores on every mention |
| LLM cost creep | Triage architecture (only ~35% escalated); per-day budget caps; batch APIs |
| OTC data is 15-min delayed everywhere retail | Display freshness honestly; signals use volume/price confirmation robust to delay; IBKR feed ($1.50–18/mo) as upgrade path |
| Survivorship bias in backtests | Ticker universe snapshots stored over time; delisted tickers retained |

## 9. Open questions for the user

1. Jurisdiction/broker context for eventual acting on signals (affects paper-trading integration choice; plan assumes Alpaca).
2. Budget ceiling for the validation phase (plan assumes ≤ ~$150/mo until the Phase 4 gate).
3. Single-user internal tool first, or multi-user from day one? (Plan assumes single-user internal until validation passes.)

---

## 10. Document map

- `docs/PLAN.md` — this file (master plan, kept current).
- `docs/ARCHITECTURE.md` — to be created in Phase 0 with the concrete implementation details.
- `docs/DATA_SOURCES.md` — to be created in Phase 1: per-source endpoints, auth, rate limits, quirks.
- `docs/audits/` — validation reports, backtest results, decision-gate write-ups.
- `docs/plans/` — scoped feature plans with tradeoff analyses (e.g. the Trade Cockpit).
