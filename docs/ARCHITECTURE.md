# Pennyhunt — Architecture

> Last updated: 2026-07-04
> Status: Phase 0–1 implemented, plus lexicon sentiment + rollups + signal engine v1
> with backtest-validated market gate + live confidence scoring (Phase 2/3),
> backtesting v4 with exit simulation, walk-forward confidence and Kelly
> portfolio simulation (Phase 4 in progress).
> **Production is LIVE at https://pennyhunt.wecode.dev** — see
> `docs/DEPLOYMENT.md` for the Forge server layout, user-space Postgres 16,
> systemd user units (Horizon/Reverb/backfill) and deploy procedure.

## Stack

| Layer | Implementation |
|---|---|
| Backend | Laravel 13.18 (PHP 8.4), official React starter kit |
| Frontend | Inertia 3 + React 19 + TypeScript + Tailwind 4 + shadcn/ui, dark theme default |
| Real-time | Laravel Reverb + `@laravel/echo-react` (`useEchoPublic`) |
| Queues | Laravel Horizon on Redis (predis client) — queues: `default`, `ingestion`, `pipeline`, `metrics` |
| Database | PostgreSQL 16 (`pennyhunt`) |
| Charts | Recharts (mention/sentiment overlays) |
| Monitoring | Laravel Pulse (`/pulse`), Horizon dashboard (`/horizon`) |

## Runtime processes (local dev)

```bash
php artisan serve          # app
npm run dev                # vite
php artisan horizon        # queue workers
php artisan reverb:start   # websockets
php artisan schedule:work  # poll cadence
```

## Data flow

```
Scheduler (routes/console.php)
  ├─ every 15m → PollRedditViaApify (one batched run, all subs)    [queue: ingestion]
  ├─ every 2m  → PollRedditSubreddit (fallback, only if no APIFY_KEY) [queue: ingestion]
  ├─ every 30m → PollApeWisdom, PollTradestie                      [queue: ingestion]
  ├─ hourly    → PollTwitterViaApify (LIVE since 2026-07-03)             [queue: ingestion]
  ├─ every 5m  → BuildTickerMetrics('5m'), ComputeSignals          [queue: metrics]
  ├─ every 15m → BuildTickerMetrics('1h')
  ├─ hourly    → BuildTickerMetrics('1d')
  ├─ daily     → GradeSignals (06:00), ScoreAuthorPumpRisk (04:30),
  │              ScoreAuthorTrackRecords (06:30),
  │              pennyhunt:sync-market-bars (05:00, feeds the market gate;
  │                IWM benchmark + ^VIX + BTC-USD macro series ride along),
  │              ManageSignalTrades (05:10, paper-trade entries + stop/time
  │                exits on the fresh bars, then TradeAlerts risk checks),
  │              pennyhunt:sync-short-volume (05:15, FINRA Reg SHO),
  │              pennyhunt:sync-sec-filings (05:30, EDGAR dilution data),
  │              pennyhunt:backfill-llm-features + pennyhunt:train-gbm
  │                (07:00/07:15 shadow retrain, NO auto-activate)
  ├─ every 15m → RefreshOpenTradeQuotes (US market hours only; indicative
  │              quotes + unrealized P&L for OPEN paper positions)
  └─ weekly    → SyncTickerUniverse (SEC company_tickers.json + CIKs, +FMP enrich)

Trade engine (forward test):
  SignalFired → OpenTradeForSignal listener → TradeEngine::createForSignal
    → signal_trades row (pending_entry) for signals ≥ the active model's
      calibrated trade tier (metrics.trade_tier.calibrated_p, now 0.124);
      half-Kelly size suggestion from the model's own run payoff ratio
  ManageSignalTrades → TradeEngine::sync
    → entry = next session's open; stop = entry×0.90; day-5 close time exit;
      SAME pessimistic OHLC fills as Backtester::simulateExit (gap through
      the stop fills at the open) — live and research semantics identical
    → TradeAlerts: stop proximity ≤3% (intraday, from quote refresh),
      time-exit-next-session, dilution filing since entry, mention collapse
      (>70% vs fire day) → system alert_events (kind=trade_*, deduped/day)
  broadcasts: channel pennyhunt.trades, event trade.updated
    (created/opened/closed/cancelled/quote) → blotter + radar live-reload

PollRedditViaApify → ApifyClient (trudax/reddit-scraper-lite, pay-per-result)
  one run scrapes /r/<sub>/new/ for all 15 subs; postDateLimit = last poll
  → items mapped to raw-Reddit thing shape → RedditIngestor (same as below)
  steady-state runs ~5 min, backfills up to ~13 min; job is ShouldBeUnique(900s);
  REDIS_QUEUE_RETRY_AFTER=960 and Horizon timeout=900 keep the queue from
  re-delivering it mid-flight (default retry_after=90 caused MaxAttemptsExceeded)

PollTwitterViaApify → ApifyClient (apidojo/twitter-scraper-lite, event-priced)
  searches cashtags of Reddit-trending tickers (top 30 by 24h mentions, OR-
  chunked ×10, -filter:retweets min_faves:5) → TwitterIngestor → raw_posts +
  authors (platform=twitter, followers/verified in stats) → same ProcessRawPost
  pipeline. Ingest gates: like floor (config apify.twitter.min_likes) +
  TweetSpamScanner (crypto airdrop/wallet promos, contract addresses, cashtag
  stuffing >6, telegram funnels — word-boundary matched, equity context
  rescues). Second layer: LlmPostClassifier emits off_topic; true on a twitter
  post deletes its post_ticker_mentions (crypto token sharing the $symbol
  must not count as stock mentions).
  ANALYTICS QUARANTINE (2026-07-04): tweets are display-only. AnalyticsGate
  (config pennyhunt.analytics.include_twitter, default false) excludes
  twitter mentions from BuildTickerMetrics rollups/z-scores, Backtester
  daily stats, MarketIntelligence mention features and LlmAggregates —
  so neither live signals nor GBM training see twitter data until it is
  validated. UI feed/ticker tape and the verified-voices panel still show
  tweets.

PollRedditSubreddit → RedditClient (app-only OAuth, fallback) → RedditIngestor
  → raw_posts (dedupe on source+external_id) + authors
  → ProcessRawPost per new post                                    [queue: pipeline]
      ├─ TickerExtractor → post_ticker_mentions (confidence-tiered)
      ├─ LexiconSentiment → post_sentiments.lexicon_score
      └─ ClassifyPostWithLlm (only if OPENAI_API_KEY or ANTHROPIC_API_KEY set,
         post mentions a ticker, ≥40 chars, under PENNYHUNT_LLM_MAX_PER_DAY) →
         post_sentiments.llm_* (post_type/direction/conviction/pump_suspicion/
         catalyst) via LlmPostClassifier (OpenAI gpt-5-mini preferred,
         Anthropic Haiku fallback)
  → broadcast FeedUpdated (batch-level ping) on channel pennyhunt.feed

BuildTickerMetrics → ticker_metrics (5m/1h/1d buckets)
  upsert SQL aggregation + z-scores vs 30-day trailing baseline per ticker

Backfill & backtesting (Phase 4):
  pennyhunt:backfill-reddit   → ArcticShiftClient (free Pushshift successor)
    streams historical posts through the SAME RedditIngestor + pipeline
  pennyhunt:sync-market-bars  → YahooMarketData (keyless daily OHLC)
    → market_bars for every ticker with mentions in the window
  pennyhunt:sync-sec-filings  → EdgarClient (data.sec.gov, keyless, 8 req/s)
    → sec_filings (S-3/F-3 shelves, 424B takedowns, 8-K/10-K/Q…)
    → ticker_share_counts (XBRL cover-page shares outstanding)
  pennyhunt:sync-short-volume → RegShoClient (FINRA CNMS+CORF daily files)
    → short_volumes (short_ratio per ticker-day, universe-filtered)
  SyncCompanyProfile (queue: ingestion) → PolygonClient (Stocks Starter)
    → ticker_profiles (description/SIC/market cap/shares/employees) +
    ticker_financials (SEC XBRL quarterly + annual statements);
    dispatched lazily from the ticker page when profile missing/stale >7d
  pennyhunt:classify-posts    → LlmPostClassifier over posts on backtest
    candidate (ticker, day)s only — targeted, cost-bounded historical coverage
  MarketIntelligence (Services\Features) — point-in-time feature store
    load(tickerIds, from, to) preloads filings/share counts/short volumes/
    IWM + ^VIX + BTC-USD bars/site-wide + per-ticker mention counts;
    features(tickerId, day) answers as-of:
    short_ratio, atm_filed_90d, active_shelf, share_growth_12m,
    market_ret_5d (IWM 5-session), site_mention_z (vs trailing 30d),
    vix (last close ≤6d stale), btc_ret_5d (retail risk appetite),
    mention_streak (consecutive rising-mention days — momentum continuation).
    SAME instance API serves Backtester (bulk historical) and SignalEngine
    (today) — research and live definitions cannot drift.
  RunBacktest (queue) → Backtester service
    as-of rolling baselines (no look-ahead), SignalMath (shared with live
    SignalEngine), entry at next-day open, +1/+3/+5d returns, control group
    of non-fired mention-days, reaction-vs-prediction classification,
    volume z-score + dollar-volume features, MarketIntelligence features
    persisted per event, friction-adjusted net PnL,
    optional gates (min_volume_z / max_pre_run / max_entry_price),
    optional exit rules (stop_loss / take_profit; pessimistic daily-OHLC
    fills: gaps through the stop fill at the open, stop-before-take on
    straddling bars) → exit_return / exit_reason / exit_day / exit_date
    → backtest_events (one row per scored candidate day, fired or control)
    → backtest_runs.results JSON (summary + winner profile)
    → post-processing (same job, best-effort):
        ConfidenceTrainer.walkForwardScore — monthly-refit logistic P(hit),
          each month scored by a model trained only on prior months →
          backtest_events.confidence + results.confidence (Brier vs
          base-rate reference, reliability quintiles)
        PortfolioSimulator — chronological equity replay of fired scored
          trades: equal weight vs half/full Kelly (f* = p−(1−p)/b from
          AS-OF realized net-win rate + payoff per confidence tercile),
          caps at 10% equity + 1% signal-day dollar volume →
          results.portfolio (strategy stats + merged equity curves)
    → /backtests UI (paginated signals newest-first with confidence column;
      portfolio panel with equity curves; polls while running)
  pennyhunt:fit-weights → WeightFitter
    single-split logistic research view over backtest_events,
    out-of-sample precision@k vs base rate, stored in results.weight_fit
    (shares LogisticRegression + feature builder with ConfidenceTrainer)
  pennyhunt:train-confidence → ConfidenceTrainer
    walk-forward scores a run AND trains/activates the LIVE model
    (signal_models row: weights/bias/standardization + holdout metrics);
    only this command changes the active model — backtest post-processing
    never does
  pennyhunt:simulate-portfolio → PortfolioSimulator (manual re-run)
  pennyhunt:backfill-llm-features → LlmAggregates (phase B feature block)
    per-(ticker, day) aggregates of the LLM post classifications, shared
    single-definition between Backtester and SignalEngine (like
    MarketIntelligence): llm_coverage (share of the day's mention posts
    with an LLM verdict — the model discounts thin-coverage days),
    llm_direction (bullish=+1/bearish=−1 mean), llm_conviction,
    llm_pump_suspicion, llm_dd/hype/news_share, llm_catalyst_share.
    Persisted on backtest_events (Backtester writes them on new runs; the
    command recomputes them onto existing runs, idempotent), part of
    ConfidenceTrainer::FEATURES (23), stored in signal breakdown.llm.
    Point-in-time safe: verdicts come from a fixed prompt over post text
    only. tests/Feature/LlmAggregatesTest.php
  pennyhunt:train-gbm → GBM confidence model (Phase C, LIVE 2026-07-04)
    exports the run's events (ConfidenceTrainer::features definitions) →
    scripts/train_gbm_model.py (.venv-ml python, config pennyhunt.ml.python):
    walk-forward metrics (monthly refits, no look-ahead), final
    HistGradientBoosting fit, isotonic calibration fitted on OUT-OF-SAMPLE
    scores only → JSON artifact (flattened tree nodes + isotonic
    breakpoints + parity vectors) → signal_models row (parameters.type =
    "gbm"). SignalModel::predict() dispatches on type: logistic (legacy)
    or pure-PHP tree walk + sigmoid + isotonic interpolation (~10ms, no
    Python on the live scoring path). Parity vectors re-scored through the
    PHP evaluator at import — mismatch aborts. Research scripts:
    scripts/phase_c_gbm.py / phase_c_robustness.py.
    docs/audits/2026-07-04-phase-c-gbm.md
  ScoreAuthorPumpRisk (nightly 04:30)
    heuristic author pump-risk (ticker concentration + posting burst +
    account newness) → authors.pump_risk_score → discounts quality weight
  ScoreAuthorTrackRecords (nightly 06:30)
    Laplace-smoothed hit rate over the backtest candidate days each author
    posted into (graded vs the latest done run's event labels, ≥3 graded
    mentions) → authors.track_record_score + track_record_n
  EvaluateAlertRules (listener on SignalFired)
    composite_threshold / ticker_signal / mention_spike rules → alert_events
    (in-app) + optional mail

Market data invariant: YahooMarketData applies Yahoo's split events to
restate pre-split bars on the post-split basis — Yahoo does NOT retro-adjust
many small-cap/OTC series, and an unadjusted reverse split fabricates fake
100x "winners" in backtests.

ComputeSignals → SignalEngine
  components: acceleration (sigmoid of mention z-score, w=0.40)
              breadth (unique authors/mentions, w=0.20)
              sentiment (weighted lexicon, w=0.25)
              cross_source (rising on aggregator, w=0.15)
  fire if composite ≥ 0.65 (config), 6h cooldown, min 3 mentions/h
  THEN market-confirmation gate (backtest-validated, config
  pennyhunt.signals.market_gate): last close ≤ $5 AND volume z ≥ 2 vs
  trailing 30 bars; no/stale bars → on-demand Yahoo sync → still none →
  suppressed ("can't price it, can't trade it"); gate verdict stored in
  breakdown.market_gate (incl. pre_return_3d + dollar_volume features)
  THEN confidence: active SignalModel (signal_models) scores P(hit) from
  the same features the backtest model trained on — the 6 social/market
  features PLUS the 9 MarketIntelligence features (dilution/short/regime/
  macro/momentum, 15 total),
  computed for today and stored in breakdown.intel → signals.confidence +
  model_version (null when no model active or no market data — an honest
  unknown beats a made-up probability)
  → signals row (full breakdown JSON) + broadcast SignalFired on pennyhunt.signals

GradeSignals → forward_return_1d/3d/5d via market_bars (Yahoo, keyless)
  graded_at only set once 5d window closes → Signals page track record
```

## Key implementation decisions

- **Ticker extraction** (`App\Services\Nlp\TickerExtractor`): cashtags = 1.0
  confidence; bare uppercase symbols validated against the active universe = 0.7;
  62 word-colliding symbols (CEO, DD, YOLO…, `config/pennyhunt.php`) only count
  as cashtags. Universe cached 30 min (`tickers:active_symbols`).
- **Sentiment tiers**: tier 0 lexicon (implemented, full coverage), tier 1
  FinBERT and tier 2 LLM come with the Python sidecar (Phase 2b) — the
  `post_sentiments` table already has all columns so scores land side-by-side
  for backtest comparison.
- **Author quality weight**: account age (≤2y) + log karma (≤100k), scaled by
  (1 − pump_risk_score), floored at 0.05. Mirrored in SQL inside
  `BuildTickerMetrics` for the weighted sentiment rollup.
- **Broadcast discipline**: feed pings are batch-level (one per source poll
  that ingested something), not per-post; the client debounces reloads to 1/5s.
  Signals broadcast individually (low volume).
- **Raw posts partitioning** is deferred: plain table + composite indexes are
  fine at validation scale; revisit at ~10M rows (documented tradeoff, plan §2).

## Frontend map

| Route | Page | Notes |
|---|---|---|
| `/radar` | `pages/radar.tsx` | RegimeBanner (VIX/S&P/BTC/site-buzz), leaderboard (1h z-scores + live composite, "forming" rows), open-positions rail, tier-badged recent signals, aggregator movers; live via `pennyhunt.signals` + `pennyhunt.trades` |
| `/feed` | `pages/feed.tsx` | filterable post stream (source, kind, LLM post-type, "My positions"); LLM type/direction/pump badges; off-topic excluded; live via `pennyhunt.feed` (debounced) |
| `/signals` | `pages/signals.tsx` | trade blotter: forward-test scoreboard, Positions / History / Signal-log tabs, position risk-alert chips; live via `pennyhunt.trades` |
| `/signals/{id}` | `pages/signals/show.tsx` | **signal cockpit**: trade plan (entry/stop/day-5 exit/Kelly), candle chart with entry+stop levels, decision evidence vs run #32 winner/loser medians, historical analogs, regime + dilution rails, mention momentum, social tape |
| `/tickers/{symbol}` | `pages/tickers/show.tsx` | 12-month candle chart (`market_bars`) with signal markers, mention/sentiment chart, driving posts, verified-voices panel, dilution KPIs with InfoTips, signal history, aggregator cross-view |
| `/backtests` | `pages/backtests.tsx` | run form (gates + exits), runs table, summary + winner profile + portfolio panel (equal vs Kelly equity curves, calibration line), paginated signals with confidence |
| `/watchlists` | `pages/watchlists.tsx` | default watchlist, add/remove symbols |
| `/sources` | `pages/sources.tsx` | ingestion health per source, credential warnings |

Shared UI: `components/pennyhunt/badges.tsx` (SentimentBadge, ZScoreBadge,
PumpRiskBadge, FreshnessChip, TierBadge, TradeStatusBadge),
`candle-chart.tsx` (Lightweight Charts + markers + price levels),
`info-tip.tsx`. UX rules: every number shows "compared to what", bullish
sentiment never appears without pump risk, and nothing invented sits next
to something validated (every derived number carries its backtest
provenance in an InfoTip).

## Tests

- `tests/Unit/TickerExtractorTest.php` — cashtags, ambiguity, inactive symbols
- `tests/Unit/LexiconSentimentTest.php` — polarity, negation, bounds
- `tests/Feature/DashboardTest.php` — all main pages render for auth users
- `tests/Feature/PollRedditViaApifyTest.php` — Apify run happy path (faked HTTP),
  failure marking, no-token no-op
- `tests/Feature/PollTwitterViaApifyTest.php` — cashtag targeting, tweet
  ingestion + author stats, nothing-trending no-op, disabled no-op
- `tests/Feature/BacktesterTest.php` — signal firing/grading, exit simulation
  (gapped take fill, entry-day stop-before-take)
- `tests/Feature/SignalEngineGateTest.php` — market gate pass/price-cap/
  volume/no-data/disabled paths; live confidence from active model / null
  without one
- `tests/Feature/ConfidenceTrainerTest.php` — walk-forward scoring beats
  base-rate Brier on a learnable pattern, no look-ahead persistence, model
  train/activate/deactivate lifecycle, too-few-events guard
- `tests/Feature/PortfolioSimulatorTest.php` — Kelly concentrates in the
  profitable tercile and skips no-edge trades, liquidity cap binds,
  no-scored-trades error
- `tests/Feature/SignalBarsEndpointTest.php` — bars + entry/stop/time-exit
  annotations, auth required
- `tests/Feature/TradeEngineTest.php` — tier gating, next-open entry fill,
  gap-through-stop at open, entry-day intraday stop, day-5 time exit,
  partial-bars stay open, entry-timeout cancel
- `tests/Feature/TradeAlertsTest.php` — stop proximity (+ per-day dedupe),
  dilution filing, time-exit eve, mention collapse
- `tests/Feature/SignalCockpitPageTest.php` — blotter + cockpit Inertia props
- `tests/Unit/SignalModelGbmTest.php` — PHP GBM tree traversal, missing
  features, isotonic interpolation/clipping
- Run: `php artisan test --parallel` (126 passing as of this writing)
