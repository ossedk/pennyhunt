# Pennyhunt

Pennyhunt ingests penny-stock and momentum chatter from Reddit and similar
forums, converts it into per-ticker sentiment and attention signals, and tests
one hypothesis with statistical honesty: **can retail-forum sentiment predict
outsized price moves before they happen?**

Docs: [`docs/PLAN.md`](docs/PLAN.md) (master plan & status) ·
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) ·
[`docs/DATA_SOURCES.md`](docs/DATA_SOURCES.md)

## Stack

Laravel 13 · Inertia 3 · React 19 · TypeScript · Tailwind 4 · shadcn/ui (dark) ·
PostgreSQL 16 · Redis (Horizon queues) · Laravel Reverb (WebSockets) · Recharts

## Local development

Prerequisites: PHP 8.3+, Composer, Node 20+, PostgreSQL 16, Redis.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
createdb pennyhunt
php artisan migrate --seed

# Run each in its own terminal (or use a process manager):
php artisan serve          # app        → http://localhost:8000
npm run dev                # vite
php artisan horizon        # queue workers (ingestion/pipeline/metrics)
php artisan reverb:start   # websockets
php artisan schedule:work  # polling cadence

# One-time reference data:
php artisan tinker --execute="dispatch_sync(new \App\Jobs\Ingestion\SyncTickerUniverse);"
```

Seeded login: `hunter@pennyhunt.local` / `password`.

## Enable data sources

- **Reddit via Apify (primary, live)** — set `APIFY_KEY` in `.env`. One batched
  [reddit-scraper-lite](https://apify.com/trudax/reddit-scraper-lite) run every
  15 min covers all 15 subreddits; pay-per-result billing tracks new-post
  volume (~$1–5/day, posts only). See `docs/DATA_SOURCES.md` for cost controls.
- **Reddit native OAuth (fallback)** — create a free script app at
  reddit.com/prefs/apps and set `REDDIT_CLIENT_ID` / `REDDIT_CLIENT_SECRET`;
  used automatically when `APIFY_KEY` is empty. Non-commercial research only.
- **ApeWisdom** — works out of the box (no key).
- **Market bars (Yahoo)** — works out of the box (no key); powers backtests,
  signal outcome grading and the live market-confirmation gate.
- **X/Twitter via Apify** — **active** (`PENNYHUNT_TWITTER_ENABLED=true`,
  same `APIFY_KEY`). Hourly cashtag confirmation on Reddit-trending tickers
  (~$1–2.50/day); feeds mention metrics and the ticker-page verified-voices
  panel. Quality gates: `min_faves:5` in the search query + ingest-side like
  floor (`PENNYHUNT_TWITTER_MIN_LIKES`), heuristic crypto/bot spam scanner
  (`TweetSpamScanner` — airdrop/wallet promos, cashtag stuffing, contract
  addresses), and an LLM `off_topic` verdict that deletes ticker mentions for
  crypto tokens sharing the stock's $symbol.
- **FMP** (`FMP_API_KEY`) — optional ticker enrichment (exchange, market cap).
- **SEC EDGAR dilution data** — works out of the box (free, keyless). Nightly
  `pennyhunt:sync-sec-filings` pulls shelf registrations (S-3/F-3), ATM
  takedowns (424B) and XBRL share counts for mentioned tickers; set
  `SEC_USER_AGENT` to your real contact per SEC fair-access policy.
- **FINRA Reg SHO short volume** — works out of the box (free, keyless).
  Nightly `pennyhunt:sync-short-volume` ingests daily short-sale ratios.
- **LLM post classification** (`OPENAI_API_KEY`, active — or
  `ANTHROPIC_API_KEY` as fallback) — classifies ticker posts into
  dd/technical/hype/news + conviction + pump suspicion (gpt-5-mini by
  default, capped by `PENNYHUNT_LLM_MAX_PER_DAY`, ~$1–2/month live).
- **Polygon.io Stocks Starter** (`POLYGON_API_KEY`, active) — company
  profiles, SEC XBRL financials (the ticker-page Company/Financials panels)
  and, for phase D, minute bars for stop-fill validation.
- **Macro context (Yahoo, keyless)** — `^VIX` and `BTC-USD` bars ride along
  with `pennyhunt:sync-market-bars`; MarketIntelligence turns them into
  point-in-time `vix` / `btc_ret_5d` features.

## What to sign up / pay for

| Service | Cost | Needed for | Status |
| --- | --- | --- | --- |
| Apify (already active) | ~$1–5/day | Reddit ingestion (live) | **active** |
| SEC EDGAR | free, no signup | dilution features | **active** |
| FINRA Reg SHO daily files | free, no signup | short-flow features | **active** |
| OpenAI API key | ~$1–2/month live; ~$25 one-off historical backfill | LLM post classification (gpt-5-mini) | **active** |
| Polygon.io Stocks Starter | $29/month | company profiles + financials, phase-D minute bars | **active** |
| Apify X/Twitter actor (same key) | ~$1–2.50/day | cashtag confirmation + verified-voices panel | **active** |
| Alpaca account (free tier) | free | paper-trading forward test | sign up: alpaca.markets |

FINRA's *bi-monthly short interest* API (deeper than daily short volume) is
also free but requires a FINRA API Console account — optional upgrade later.

## Backtesting

```bash
php artisan pennyhunt:backfill-reddit --months=6   # Arctic Shift history (free)
php artisan pennyhunt:sync-market-bars             # daily OHLC for mentioned tickers
```

Then run backtests from `/backtests`: as-of baselines (no look-ahead),
next-day-open entries, split-adjusted bars, friction-adjusted net PnL,
control-group base rates, winner profiles, market-confirmation gates
(volume z, pre-run cap, price cap) and exit rules (stop-loss / take-profit
with pessimistic gap fills). Every finished run is automatically
confidence-scored (walk-forward monthly-refit logistic, no look-ahead) and
replayed through a portfolio simulation comparing equal-weight vs half/full
Kelly sizing with equity and liquidity caps.

```bash
php artisan pennyhunt:fit-weights        # research: single-split weight fit
php artisan pennyhunt:train-confidence   # walk-forward score + activate the LIVE confidence model
php artisan pennyhunt:simulate-portfolio # re-run the Kelly portfolio comparison
php artisan pennyhunt:sync-sec-filings   # EDGAR shelf/ATM filings + share counts (free)
php artisan pennyhunt:sync-short-volume  # FINRA daily short ratios (free; --days=730 backfills)
php artisan pennyhunt:classify-posts     # targeted LLM classification of candidate-day posts
```

Backtest events now carry point-in-time dilution (`active_shelf`,
`atm_filed_90d`, `share_growth_12m`), short-flow (`short_ratio`), regime
(`market_ret_5d` vs IWM, `site_mention_z`), macro (`vix`, `btc_ret_5d`) and
momentum-continuation (`mention_streak` — consecutive rising-mention days)
features; the confidence model trains on all 15, and the live engine scores
signals with the same definitions via `MarketIntelligence`.

Latest validation notes:
[`docs/audits/2026-07-03-two-regime-flagship.md`](docs/audits/2026-07-03-two-regime-flagship.md)
— the two-regime test (12 months, run #31) **invalidated the single-regime
edge**: −2.88% net/trade overall; the earlier +1.70% was partly baseline
warm-up artifact. What survives: 4.4× precision lift, clean monotone
confidence ranking (top quintile 8.0% vs bottom 1.8% realized), and Kelly
sizing that refused 86% of trades and cut the drawdown from 65% to 6%. The
live engine runs the same gates + the activated 12-feature confidence model
(`PENNYHUNT_MARKET_GATE`).

## Dashboards

- `/radar` — live attention leaderboard and cross-source movers
- `/tickers/{symbol}` — quote header with day change + key-stats strip,
  TradingView-style candlestick/volume chart (range switcher, signal markers,
  OHLC crosshair legend), company profile + quarterly financials (Polygon),
  dilution & short-flow snapshot with explainer tooltips on every KPI,
  verified-Twitter feed (live)
- `/feed` — normalized post stream with sentiment and pump-risk badges
- `/signals` — every fired signal with confidence, gate verdict, realized
  forward returns and an expandable post-signal price chart
- `/backtests` — replay history through the live scoring engine, graded vs control
- `/sources` — ingestion health
- `/horizon`, `/pulse` — ops

## Tests & quality

```bash
php artisan test --parallel   # Pest
vendor/bin/pint --dirty       # PHP formatting
npm run lint                  # ESLint
npx tsc --noEmit              # types
```
