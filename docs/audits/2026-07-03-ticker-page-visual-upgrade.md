# Ticker page visual upgrade + Twitter ingestion live — 2026-07-03

## What changed

### 1. X/Twitter ingestion is live
- `PENNYHUNT_TWITTER_ENABLED=true` set — the existing `APIFY_KEY` already has
  access to `apidojo/twitter-scraper-lite` (event-priced, ~$0.016/query).
- Verified with a synchronous live run: **300 tweets ingested, 132 verified
  authors** stored with follower counts and real like counts. Hourly schedule
  (`poll-twitter-apify`) now fires; Horizon was restarted so workers see the
  new env.
- The ticker-page "verified voices" panel populates from this feed (verified
  profiles only, ranked by likes, last 30 days).

### 2. Candlestick chart (`resources/js/components/pennyhunt/candle-chart.tsx`)
- TradingView **Lightweight Charts v5** (`lightweight-charts@5.2.0`): daily
  OHLC candlesticks + color-coded volume histogram on a hidden overlay scale.
- Crosshair OHLC legend (date, O/H/L/C, day change %, volume) that tracks the
  hovered bar and falls back to the latest session.
- Range switcher (1M / 3M / 6M / 1Y / All) applied client-side over 12 months
  of bars sent by `TickerController` (now includes open/high/low).
- Fired signals rendered as orange arrow markers via `createSeriesMarkers`,
  snapped to the last session on/before the fire date.
- Penny-stock price formatting: dynamic precision (2dp ≥ $1 down to 4dp
  < $0.10).

### 3. Header + key-stats strip (`resources/js/pages/tickers/show.tsx`)
- Quote header: symbol, exchange/industry badges, last price with day-change
  % (green/red).
- Stat strip: market cap, shares out, 12M high/low, latest volume, dollar
  volume, employees, list date.

### 4. Explainer tooltips on every model KPI
- New `InfoTip` component (Radix tooltip + info glyph).
- The "Dilution & short flow" card now explains all nine features in plain
  language, including what the model learned about each (e.g. VIX ≥ 25 →
  −11.5%/trade in backtest; prospectus takedown = positive P(hit) weight but
  capped upside). Card also gained the three newest features: VIX, BTC 5d,
  mention streak — with threshold-based coloring (VIX amber ≥ 20 / red ≥ 25,
  buzz amber ≥ 1.5σ, streak green ≥ 3d).
- Financials and verified-voices cards got header explainers too.

## Verification
- Live browser check on `/tickers/GME`: candles + volume render, legend
  tracks crosshair, tooltip content verified programmatically, stat strip
  correct ($10.24B cap, 448.7M shares, 12M range $19.93–$28.10).
- Fixed in review: `list_date` rendered as raw ISO timestamp → sliced to
  Y-m-d.
- Quality: 97 backend tests pass (350 assertions), `tsc --noEmit` clean,
  ESLint clean, Pint applied.

## Follow-ups
- `SignalPriceChart` (expanded signal rows) still uses the Recharts line —
  candidate for the same candle treatment once `/signals/{id}/bars` returns
  OHLC.
- Twitter author `stats->is_verified` drives the verified filter; watch a few
  days of data to confirm verified coverage is broad enough to be useful.
