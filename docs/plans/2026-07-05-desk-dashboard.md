# The Desk — dashboard, global search, on-demand Twitter, news

> Status: SHIPPED 2026-07-05
> Scope: new landing dashboard ("Desk"), global ticker search (Cmd+K),
> on-demand X/Twitter pulls, Polygon news on ticker pages + dashboard,
> LLM-written market brief.

## Why

The platform has deep pages (radar, cockpit, blotter, ticker) but no
top-of-funnel: you log in and land on the radar leaderboard with zero
narrative context. A trader's first five minutes are "what kind of day is
it, what moved, what is the crowd screaming about, where should I look?".
The Desk answers exactly that, and global search removes the "edit the
URL to open a ticker" friction.

## Tradeoff analysis

**Dashboard identity: another leaderboard vs. a briefing.** The lazy
design is a grid of the same widgets the radar already has — that adds a
page without adding information. The Desk is instead built as a *morning
briefing*: the top of the page is a machine-written, regime-aware brief
(what changed overnight, what to watch, what to distrust), and every
widget below it answers one question in ≤5 rows (biggest tape moves,
loudest crowd names, top hyped posts, open risk, top news). Radar stays
the *hunting* surface; the Desk is the *orientation* surface. The risk of
overlap is real, so widgets deliberately link into radar/cockpit/ticker
rather than duplicating their depth.

**LLM brief: freeform prose vs. structured JSON.** Freeform prose reads
nicely but hallucinates tickers and trends. The brief generator sends the
LLM a *closed world*: a compact JSON context assembled from our own
aggregates (regime features, top movers with real returns, mention
leaders with z-scores, signal/trade state, headline titles) and instructs
it to write only about entities in that context, returning structured
JSON (headline, 2-3 paragraph body, watch-list bullets each bound to a
symbol, risk flags). Symbols are rendered as links only when they exist
in our universe — the UI never trusts free text. Cost: one gpt-5-mini
call per generation (~$0.001), scheduled hourly during market-relevant
hours; regenerating on every page view would be pure waste.

**News: store vs. proxy.** Hitting Polygon's news API per page view would
be simple but slow (300-800ms), unrankable ("top hyped news" needs a join
against our mention data), and wasteful of the unlimited-but-metered
plan. We persist into a `ticker_news` table (idempotent upsert on
Polygon's article id) synced two ways: lazily on ticker-page view (6h
cooldown per ticker, same pattern as company profiles) and hourly for the
current top-mentioned tickers so the dashboard join is always warm. The
"top hyped news" ranking is then local SQL: newest articles for tickers
ranked by 24h mention volume.

**On-demand Twitter: per-keystroke vs. per-intent.** Pulling X on every
search keystroke would burn Apify money on typos. The trigger is
*intent*: an exact-symbol search hit or a ticker-page load dispatches
`PullTwitterForTicker` — one $-cashtag query, `maxItems` capped at the
first pricing tier (~$0.016), behind a 30-minute per-ticker cache
cooldown and the existing quality gates (min likes, spam scanner, LLM
off-topic stripping). Tweets remain display-only per the analytics
quarantine; this is about having a fresh tape when a human looks, not
about feeding the model.

**Search: dedicated engine vs. Postgres.** Meilisearch/Typesense would be
overkill for a ~10k-row ticker universe. A single indexed query — exact
symbol match first, then prefix, then name ILIKE — ranked by 24h mention
count (attention-aware, so "the SOFI everyone is talking about" beats an
OTC shell with a similar name) returns in single-digit ms. Frontend is a
Cmd+K dialog (debounced fetch, keyboard navigation) available on every
page; no new npm dependency, no new infra.

## What ships

### Backend
- `ticker_news` table + `TickerNews` model; `PolygonClient::news()`;
  `SyncTickerNews` job (lazy, 6h cooldown) + `SyncTrendingNews` (hourly,
  top-25 mentioned tickers).
- `market_briefs` table + `MarketBrief` model; `MarketBriefWriter`
  service (OpenAI, structured JSON out, closed-world context in);
  `GenerateMarketBrief` job — hourly 10:00–22:00 UTC weekdays + on-demand
  dispatch when the Desk renders with a stale brief.
- `PullTwitterForTicker` job — single-cashtag Apify query, 30m cooldown,
  dispatched from ticker-page view and exact search hits.
- `SearchController` (`GET /search?q=`) — ranked ticker matches.
- `DashboardController` (`GET /`) — brief, regime + market status, tape
  movers (bars ∩ trending), mention leaders, hyped posts, open positions,
  latest signals, top news.
- Routes: `/` = Desk (auth), post-login home stays `/dashboard` → Desk.
  Radar moves to `/radar` (unchanged).

### Frontend
- `pages/dashboard.tsx` — brief hero, regime strip, widget grid.
- `components/pennyhunt/ticker-search.tsx` — Cmd+K dialog, wired into the
  sidebar header on every page.
- Ticker page: "Latest news" card (publisher, headline, age, link).
- Sidebar: "Desk" nav item (LayoutDashboard icon) above Radar.

### Guardrails
- Every LLM/news/twitter fetch is queue-side, cooldown-guarded and
  key-gated — pages never block on external APIs.
- Brief JSON is validated; symbols not in our universe render as plain
  text, never links.
