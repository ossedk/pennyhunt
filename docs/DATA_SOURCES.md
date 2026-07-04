# Pennyhunt — Data Sources

> Last updated: 2026-07-03

## Live status

| Source | Status | Auth | Notes |
|---|---|---|---|
| Reddit via Apify (15 subreddits) | **Working — primary path** | `APIFY_KEY` | Verified live 2026-07-02; batched actor run every 15 min |
| Reddit Data API (15 subreddits) | Ready — fallback | Free OAuth script app | Used automatically when `APIFY_KEY` is empty |
| ApeWisdom | **Working** | None | Verified live 2026-07-02; requires browser UA (Cloudflare) |
| Tradestie | **Blocked** | None | Active Cloudflare JS challenge on the API (July 2026) — source seeded disabled |
| SEC company_tickers.json | **Working** | None | 10,426 tickers synced; requires identifying User-Agent (`SEC_USER_AGENT`) |
| Arctic Shift (Reddit archive) | **Working** | None | Historical backfill for backtests; verified live 2026-07-03 |
| Yahoo chart API (daily OHLC) | **Working** | None | Keyless daily bars → `market_bars`; powers backtests + signal grading |
| FMP (market data) | Optional — needs key | `FMP_API_KEY` | Ticker enrichment (exchange/price); no longer required for grading |
| Stocktwits | Skipped | — | API frozen to new developers (2026) |
| X/Twitter via Apify | **Built — off by default** | `APIFY_KEY` + `PENNYHUNT_TWITTER_ENABLED` | Targeted cashtag confirmation; requires a paid Apify plan |

## Reddit via Apify — primary path (live)

**Actor**: [`trudax/reddit-scraper-lite`](https://apify.com/trudax/reddit-scraper-lite),
pay-per-result (~$3.40 / 1,000 results). `APIFY_KEY` in `.env` activates this
path; the scheduler then skips the native OAuth pollers automatically.

**How it works** (`App\Jobs\Ingestion\PollRedditViaApify` + `App\Services\Ingestion\ApifyClient`):
- One batched actor run every 15 minutes covers all 15 subreddits via
  `startUrls` (`/r/<sub>/new/` each). Runs take ~13 min end-to-end (headless
  browsers on Apify's side), so 15 min is the tightest non-overlapping cadence.
  The job is `ShouldBeUnique` to prevent overlap on slow runs.
- `postDateLimit` is set to the oldest `last_ok_at` minus a 10-min overlap, so
  the actor only returns — and only bills — genuinely new posts. First run
  backfills 24h. Dedupe on `(source_id, external_id)` absorbs the overlap.
- Items are mapped onto the same raw-Reddit "thing" shape the native path
  produces, so `RedditIngestor` handles both identically.

**Cost controls** (in `config/pennyhunt.php` → `apify`):
- `max_posts_per_subreddit` (15) caps each run at 225 results (~$0.77 worst case).
- `include_comments` (false) — comments are 10–50x post volume; enabling them
  at pay-per-result pricing means roughly $50–200/day. Posts only measured at
  ~$0.11 per 15-min cycle during US market hours ≈ **$3–10/day**.
- `include_media_links` (false) — detailed extraction (upvotes, comment counts)
  renders every post page in a browser at ~30–45s each and made a run take 15+
  min while producing partial data. Fast RSS mode gets title/body/author/
  timestamp, which is what the NLP pipeline needs; scores stay 0.
- `scroll_timeout` (10s) — the default 40s per page added ~8 min per run.

**Ops notes**:
- `REDIS_QUEUE_RETRY_AFTER=960` and Horizon `timeout: 900` exist because this
  job legitimately runs for ~13 min; with the default 90s `retry_after` the
  queue re-delivered it mid-flight and it died with `MaxAttemptsExceededException`
  (while duplicate paid actor runs kept going on Apify's side).
- The Apify account may run other actors; when debugging, filter runs by actor:
  `GET /v2/acts/trudax~reddit-scraper-lite/runs`.

## Reddit native OAuth — fallback path

1. Go to https://www.reddit.com/prefs/apps → "create another app" → type **script**.
2. Set in `.env`:
   ```
   REDDIT_CLIENT_ID=xxxx
   REDDIT_CLIENT_SECRET=xxxx
   REDDIT_USER_AGENT="macos:pennyhunt:v0.1 (research; /u/YOUR_USERNAME)"
   ```
3. Remove/empty `APIFY_KEY` and ingestion switches to this path on the next
   scheduler tick (2-min cadence). Free but non-commercial research only;
   commercial use requires a Reddit data agreement (plan §3).

## Endpoints in use

### Apify (`App\Services\Ingestion\ApifyClient`)
- `POST https://api.apify.com/v2/acts/trudax~reddit-scraper-lite/runs` — start run
- `GET https://api.apify.com/v2/actor-runs/{id}` — poll status (10s interval, transient-failure tolerant)
- `GET https://api.apify.com/v2/datasets/{id}/items?clean=true` — fetch results
- Runs exceeding the job's wait budget are aborted server-side to stop billing.

### Reddit (`App\Services\Ingestion\RedditClient`, fallback)
- `POST https://www.reddit.com/api/v1/access_token` (client_credentials, cached 50 min)
- `GET https://oauth.reddit.com/r/{sub}/new?limit=100` — posts
- `GET https://oauth.reddit.com/r/{sub}/comments?limit=100` — comments
- `GET https://oauth.reddit.com/user/{name}/about` — author enrichment (future nightly job)

Subreddits (configured in `config/pennyhunt.php`): pennystocks, wallstreetbets,
Shortsqueeze, smallstreetbets, stocks, StockMarket, investing, Daytrading,
options, WallStreetbetsELITE, Wallstreetbetsnew, RobinHoodPennyStocks,
trakstocks, SqueezePlays, OTCstocks.

### ApeWisdom (`App\Jobs\Ingestion\PollApeWisdom`)
- `GET https://apewisdom.io/api/v1.0/filter/all-stocks/page/{n}` — pages 1–3 (top 300)
- Returns mentions/upvotes + 24h-ago comparisons. **No sentiment** — buzz only.
- Quirk: Cloudflare blocks default Guzzle UA; we send a browser UA.

### Tradestie (currently disabled)
- `GET https://tradestie.com/api/v1/apps/reddit` — top-50 WSB w/ sentiment.
- Blocked by Cloudflare JS challenge as of 2026-07-02 (verified: 403 with any
  server-side UA). Re-check periodically: `curl -s -o /dev/null -w "%{http_code}" https://tradestie.com/api/v1/apps/reddit`
  → if 200, re-enable via `Source::where('key','tradestie')->update(['enabled'=>true])`.

### Arctic Shift (`App\Services\Ingestion\ArcticShiftClient`)
- `GET https://arctic-shift.photon-reddit.com/api/posts/search` — historical
  posts by subreddit + epoch range, 100/page, paginated on `created_utc`.
- Free Pushshift successor (github.com/ArthurHeitmann/arctic_shift), no auth.
- Used by `php artisan pennyhunt:backfill-reddit --months=N` which streams
  history through the same `RedditIngestor` pipeline as live data.
- Quirk: returns 422 "Timeout. Maybe slow down a bit" under load — the client
  backs off exponentially (15s → 240s) and the command is idempotent/resumable.

### Yahoo chart API (`App\Services\MarketData\YahooMarketData`)
- `GET https://query1.finance.yahoo.com/v8/finance/chart/{symbol}` — keyless
  daily OHLC bars, upserted into `market_bars`.
- Used by `php artisan pennyhunt:sync-market-bars` (backtest coverage) and
  `GradeSignals` (live signal outcome grading). Browser UA required.
- **Split adjustment is mandatory**: Yahoo does not retro-adjust many
  small-cap/OTC series. The client applies `events.splits` to restate
  pre-split bars on the post-split basis — without this, reverse splits
  fabricate fake 100x winners (see backtest v2 audit).
- Daily granularity is a deliberate validation-phase choice; a licensed feed
  is only justified if the strategy passes the Phase 4 decision gate.

### SEC (`App\Jobs\Ingestion\SyncTickerUniverse`)
- `GET https://www.sec.gov/files/company_tickers.json` — full registrant list
  **including CIK** (stored on `tickers.cik`, keys all EDGAR lookups).
- Quirk: 403 without an identifying UA. Set `SEC_USER_AGENT="Name email"`.

### SEC EDGAR dilution data (`App\Services\MarketData\EdgarClient`) — free, keyless
- `GET https://data.sec.gov/submissions/CIK{10-digit}.json` — full filing
  index per company (form + filing date + accession). We store only
  dilution/solvency-relevant forms in `sec_filings`: S-3/F-3(+ASR/A) shelves,
  424B3/B4/B5 takedowns, S-1/F-1, 8-K, 10-K/Q, 20-F, NT filings.
- `GET https://data.sec.gov/api/xbrl/companyconcept/CIK…/dei/EntityCommonStockSharesOutstanding.json`
  — cover-page shares outstanding per report → `ticker_share_counts`
  (realized dilution as a point-in-time series).
- Fair-access policy: identifying UA + max 10 req/s; the client sleeps 125ms
  between calls (~8 req/s). 404 = no XBRL facts, a normal outcome for shells.
- `php artisan pennyhunt:sync-sec-filings` — nightly 05:30 for recently
  mentioned tickers (`--skip-synced-days=7` keeps the delta small); a full
  24-month-mention backfill takes ~1.5–2h at the rate limit.
- Because filings carry their filing date, ONE sync provides features for
  any historical backtest day with zero look-ahead (`MarketIntelligence`).

### FINRA Reg SHO daily short volume (`App\Services\MarketData\RegShoClient`) — free, keyless
- `GET https://cdn.finra.org/equity/regsho/daily/CNMSshvol{YYYYMMDD}.txt`
  (NMS) + `CORFshvol{YYYYMMDD}.txt` (OTC) — pipe-delimited daily short-sale
  volume per symbol, published nightly. Facilities are summed per symbol.
- Stored in `short_volumes` (only symbols in our universe) with
  `short_ratio = short/total`; feature reads tolerate ≤6 days staleness.
- `php artisan pennyhunt:sync-short-volume --days=N` — nightly 05:15
  (`--days=3` self-heals); `--days=730` backfills 24 months (~500 files).
- Note: this is *daily short-sale flow*, not the bi-monthly *short interest*
  stock figure. The deeper short-interest dataset needs a (free) FINRA API
  Console account — optional later upgrade.

### Anthropic LLM classification (`App\Services\Nlp\LlmPostClassifier`) — optional, `ANTHROPIC_API_KEY`
- `POST https://api.anthropic.com/v1/messages` (Haiku-class model,
  `PENNYHUNT_LLM_MODEL`). Structured JSON out: post_type
  (dd/technical/hype/news/question/other), direction, conviction,
  pump_suspicion, catalyst claim → `post_sentiments.llm_*`.
- Live: dispatched from `ProcessRawPost` for ticker-mentioning posts ≥40
  chars, capped at `PENNYHUNT_LLM_MAX_PER_DAY` (default 500 ≈ $1–2/month).
- Historical: `php artisan pennyhunt:classify-posts` classifies ONLY posts
  on backtest candidate (ticker, day)s — the 2–5% of the archive that can
  move a model feature.

### FMP (optional, recommended)
- `GET /api/v3/stock/list` — exchange + price enrichment for the universe.
- `GET /api/v3/historical-price-full/{symbol}` — daily closes for signal grading.
- Starter plan (~$19–29/mo) is sufficient for Phase 1–3.

## X/Twitter via Apify — built, disabled by default

**Actor**: [`apidojo/twitter-scraper-lite`](https://apify.com/apidojo/twitter-scraper-lite),
event-based pricing: ~$0.016 per search query (first ~40 tweets included) +
$0.0004–0.002 per additional tweet. **Requires a paid Apify plan** — free
plans are demo-capped at 5 runs/month × 10 items, which is useless for
ingestion. Enable with `PENNYHUNT_TWITTER_ENABLED=true` once on a paid plan.

**Design — confirmation, not discovery**
(`App\Jobs\Ingestion\PollTwitterViaApify` + `App\Services\Ingestion\TwitterIngestor`):
- Twitter's raw cashtag stream is dominated by crypto spam, bots and
  engagement farms; broad discovery scraping is expensive and dirty. Instead,
  each hourly run searches the cashtags of tickers **already trending on
  Reddit** (≥3 mentions in 24h, top 30 by mention count), chunked into
  OR-queries of 10 (`($ABC OR $DEF …) -filter:retweets`, `sort: Latest`,
  English only).
- This gives an *independent cross-platform read* on exactly the names the
  signal engine is watching — Twitter mentions flow into `ticker_metrics`
  (acceleration/breadth) and tweets carry lexicon sentiment like any other
  post. Author followers/verification are stored in `authors.stats` for
  quality weighting.
- Cost at defaults: 3 queries/run × 24 runs/day ≈ **$1.20–2.50/day**
  depending on tweet volume (`max_items` 300/run caps the tail). Tune via
  `pennyhunt.apify.twitter` config.
- Dedupe on `(source_id, external_id)` (tweet id); source row is
  `twitter:cashtags`.

## Planned (per PLAN.md)

- ~~Quiver Quantitative ($30/mo) — WSB historical backfill~~ **not needed:
  Arctic Shift provides full historical posts for free.**
- ~~X via twitterapi.io~~ **built via Apify instead** (see above), pending a
  paid Apify plan to switch on.
- **NLP sidecar** (FastAPI + FinBERT + LLM escalation) — Phase 2b; `PENNYHUNT_NLP_URL` env is reserved.
  Priority raised: sentiment remains the dead component in fitted weights.
