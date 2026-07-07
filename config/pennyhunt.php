<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reddit (free OAuth tier, non-commercial research use)
    |--------------------------------------------------------------------------
    | Create a "script" app at https://www.reddit.com/prefs/apps and set the
    | client id/secret here. App-only (client_credentials) auth is used for
    | read-only access to public subreddits. 100 req/min limit is respected
    | by the poll cadence configured on each source row.
    */
    'reddit' => [
        'client_id' => env('REDDIT_CLIENT_ID'),
        'client_secret' => env('REDDIT_CLIENT_SECRET'),
        'user_agent' => env('REDDIT_USER_AGENT', 'macos:pennyhunt:v0.1 (research)'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Apify (Reddit Scraper Lite - primary Reddit ingestion path)
    |--------------------------------------------------------------------------
    | Pay-per-result actor (~$3.40 / 1,000 results). One batched run scrapes
    | /new/ for every enabled subreddit; postDateLimit restricts results (and
    | billing) to posts newer than the last successful poll. Comments are
    | skipped by default because their volume is 10-50x posts.
    */
    'apify' => [
        'token' => env('APIFY_KEY'),
        'reddit_actor' => 'trudax~reddit-scraper-lite',
        // Hard cap on posts fetched per subreddit per run (cost guardrail).
        'max_posts_per_subreddit' => 15,
        // Include comment scraping (expensive at pay-per-result pricing).
        'include_comments' => false,
        // Detailed extraction (upVotes, numberOfComments) visits every post
        // page in a headless browser (~30-45s each) - unusable at 15 subs.
        // False = fast RSS mode: title/body/author/timestamp only.
        'include_media_links' => false,
        // How far back the very first run may backfill.
        'first_run_backfill_hours' => 24,
        // Seconds the actor scrolls each subreddit page. Default 40s made a
        // 15-sub run take ~15 min; the newest posts load without scrolling.
        'scroll_timeout' => 10,

        /*
        | X/Twitter via apidojo/twitter-scraper-lite (event-based pricing:
        | ~$0.016 per search query + ~$0.0004-0.002 per tweet past the first
        | ~40). Targeted cashtag confirmation, NOT firehose discovery: each
        | run searches cashtags of tickers already trending on Reddit, so
        | volume (and cost) stays bounded. Disabled by default — requires a
        | paid Apify plan (free plan is demo-capped at 5 runs/month).
        */
        'twitter' => [
            'enabled' => (bool) env('PENNYHUNT_TWITTER_ENABLED', false),
            'actor' => 'apidojo~twitter-scraper-lite',
            // How many trending tickers to search per run (chunked into
            // OR-queries of 10 cashtags => tickers/10 queries per run).
            'max_tickers' => (int) env('PENNYHUNT_TWITTER_MAX_TICKERS', 30),
            // Hard cap on tweets per run (cost guardrail).
            'max_items' => (int) env('PENNYHUNT_TWITTER_MAX_ITEMS', 300),
            // Minimum like count — applied in the search query (min_faves:N,
            // so we don't pay for junk items) AND as an ingest guard.
            'min_likes' => (int) env('PENNYHUNT_TWITTER_MIN_LIKES', 5),
            // Trending = at least this many Reddit mentions in the lookback.
            'min_mentions' => 3,
            'lookback_hours' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics source gating
    |--------------------------------------------------------------------------
    | While Twitter/X data quality is unproven (bots, crypto-cashtag
    | collisions, parody accounts), tweets are DISPLAY-ONLY: they appear in
    | the feed/ticker tape but are excluded from ticker metric rollups,
    | z-scores, live signal computation, backtest daily stats, LLM feature
    | aggregates and (therefore) GBM training data. Flip to true only after
    | Twitter data has been validated against backtest outcomes.
    */
    'analytics' => [
        'include_twitter' => (bool) env('PENNYHUNT_TWITTER_IN_ANALYTICS', false),
    ],

    // Subreddits polled by the ingestion scheduler. Each becomes a source row.
    'subreddits' => [
        'pennystocks',
        'wallstreetbets',
        'Shortsqueeze',
        'smallstreetbets',
        'stocks',
        'StockMarket',
        'investing',
        'Daytrading',
        'options',
        'WallStreetbetsELITE',
        'Wallstreetbetsnew',
        'RobinHoodPennyStocks',
        'trakstocks',
        'SqueezePlays',
        'OTCstocks',
        // Phase G candidate-flow expansion (statistical power).
        'pennystocksDD',
        'Biotechplays',
        '10xPennyStocks',
        'StocksAndTrading',
        'squeeze_stocks',
    ],

    // SEC fair-access policy requires identifying UA: "App Name contact@email"
    'sec_user_agent' => env('SEC_USER_AGENT', 'Pennyhunt research pennyhunt@example.com'),

    // Small-cap regime benchmark (must exist in tickers + market_bars).
    'benchmark_symbol' => env('PENNYHUNT_BENCHMARK', 'IWM'),

    // Macro context series (Yahoo, keyless): VIX = fear gauge, BTC = retail
    // risk-appetite proxy, SPY = large-cap anchor for the IWM-vs-SPY
    // small-cap risk-appetite spread, XBI = biotech speculative regime.
    // Synced alongside market bars, consumed by MarketIntelligence as
    // point-in-time features.
    'macro_symbols' => [
        'vix' => '^VIX',
        'btc' => 'BTC-USD',
        'spy' => 'SPY',
        'xbi' => 'XBI',
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM post classification (phase B — optional, key-gated)
    |--------------------------------------------------------------------------
    | Classifies ticker-mentioning posts into type (dd/technical/hype/news/…),
    | direction, conviction and pump suspicion. Runs when an OpenAI or
    | Anthropic key is configured (OpenAI preferred when both are set).
    | max_per_day caps spend; at gpt-5-mini pricing ~500 posts/day ≈ $1/month.
    */
    'llm' => [
        'openai_api_key' => env('OPENAI_API_KEY'),
        'openai_model' => env('PENNYHUNT_LLM_OPENAI_MODEL', 'gpt-5-mini'),
        'anthropic_api_key' => env('ANTHROPIC_API_KEY'),
        'anthropic_model' => env('PENNYHUNT_LLM_MODEL', 'claude-haiku-4-5'),
        'max_per_day' => (int) env('PENNYHUNT_LLM_MAX_PER_DAY', 500),
        // Posts shorter than this carry no classifiable content.
        'min_text_length' => 40,
    ],

    /*
    |--------------------------------------------------------------------------
    | Polygon.io (Stocks Starter — company profiles, financials, minute bars)
    |--------------------------------------------------------------------------
    */
    'polygon' => [
        'api_key' => env('POLYGON_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Market data
    |--------------------------------------------------------------------------
    | FMP (financialmodelingprep.com) is the phase-1 provider. Without a key
    | the ticker universe is synced from the SEC's free company_tickers.json.
    */
    'fmp' => [
        'api_key' => env('FMP_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticker extraction
    |--------------------------------------------------------------------------
    | Symbols that collide with common English/forum words. Bare-word matches
    | for these are ignored; only $CASHTAG references count.
    */
    'ambiguous_symbols' => [
        'A', 'ALL', 'AI', 'AM', 'AN', 'ANY', 'APE', 'APP', 'ARE', 'AT', 'ATH', 'BE', 'BEST', 'BIG',
        'BRO', 'BUY', 'BY', 'CAN', 'CASH', 'CEO', 'CFO', 'CTO', 'COST', 'DD', 'DO', 'EDIT', 'EOD',
        'EPS', 'ETF', 'EV', 'EVER', 'EYE', 'FAST', 'FOR', 'FREE', 'FUD', 'FULL', 'GAIN', 'GDP', 'GO',
        'GOOD', 'HAS', 'HE', 'HODL', 'HOLD', 'HUGE', 'IMO', 'IPO', 'IRS', 'IT', 'ITM', 'JOB', 'KNOW',
        'LFG', 'LIFE', 'LOL', 'LOVE', 'LOW', 'MAIN', 'MAN', 'MOON', 'NEW', 'NEXT', 'NICE', 'NOW',
        'ON', 'ONE', 'OP', 'OPEN', 'OR', 'OTC', 'OUT', 'PC', 'PLAY', 'PM', 'POST', 'PT', 'PUMP',
        'REAL', 'RH', 'RIP', 'ROI', 'RUN', 'SAFE', 'SEC', 'SEE', 'SELL', 'SO', 'STAY', 'SUB', 'TA',
        'TWO', 'UK', 'UP', 'USA', 'VERY', 'WAY', 'WELL', 'WSB', 'YOLO', 'YOU',
        // Trading slang & finance acronyms that read as bare "symbols" but
        // almost never mean the ticker ("SL and TP", "3 DTE", "high OI").
        // Not in the English wordlist (they're not dictionary words), so
        // they need explicit listing. Cashtag ($TP) still counts.
        'TP', 'SL', 'HOD', 'LOD', 'AH', 'FD', 'FDS', 'OI', 'IV', 'DTE', 'CSP', 'PMCC',
        'PE', 'PB', 'MC', 'FA', 'PNL', 'NAV', 'YTD', 'QTD', 'GTC', 'AUM', 'CAGR',
        'RSI', 'MACD', 'VWAP', 'EMA', 'SMA', 'ATR', 'ADX', 'OBV',
        'CPI', 'PPI', 'FOMC', 'QE', 'QT', 'DXY', 'NFT', 'ICO', 'SPAC', 'ADR',
        'NYSE', 'AMEX', 'IBKR', 'PFOF', 'HFT',
    ],

    /*
    |--------------------------------------------------------------------------
    | Signal engine
    |--------------------------------------------------------------------------
    */
    /*
    |--------------------------------------------------------------------------
    | Voices leaderboard — grading reddit authors' calls against market bars
    |--------------------------------------------------------------------------
    | A call = an author's first non-bearish post mentioning a ticker (14-day
    | dedupe window), priced at the next session open. Win/loss thresholds
    | mirror the backtester's hit definition so numbers are comparable.
    */
    'voices' => [
        // Peak close within the horizon must clear this to count as a win.
        'win_threshold' => (float) env('PENNYHUNT_VOICES_WIN', 0.30),
        // Day-horizon close at/below this is a loss; between = flat.
        'loss_threshold' => (float) env('PENNYHUNT_VOICES_LOSS', -0.15),
        // Trading sessions after entry used for grading.
        'horizon_sessions' => 5,
        // A new post by the same author on the same ticker within this many
        // days is the same call, not a new one.
        'dedupe_days' => 14,
        // Minimum graded (non-pending) calls before an author can rank.
        'min_calls' => 5,
        // Twitter coverage is younger/sparser (on-demand pulls) — lower bar,
        // Wilson ranking keeps small samples honest anyway.
        'min_calls_twitter' => 3,
        // Only authors who posted within this many days can rank — dormant
        // accounts are history, not voices. X gets a wider window: our view
        // of X activity is partial (on-demand pulls), so a hard 21 days
        // would drop accounts we simply haven't pulled lately.
        'active_days' => 21,
        'active_days_twitter' => 45,
        // Rows kept per weekly snapshot (per platform).
        'leaderboard_size' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Moonshot model-first firing (Phase F)
    |--------------------------------------------------------------------------
    | Fires signals directly from the moonshot head (P(+75%/5d)) over ALL
    | hourly candidates — bypassing the composite threshold that had 8.6%
    | recall on winners. Gates mirror the exit lab's only both-halves-
    | positive cell (run 35: n=24, +26% net/trade, PF 3.6): raw p >= 0.15,
    | no chasing, tradeable price band, small-cap regime alive. Trades go
    | to the 'moonshot' paper book (no stop, day-5 time exit).
    */
    /*
    |--------------------------------------------------------------------------
    | Alert delivery (email)
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        // Falls back to the first user's email when unset.
        'email' => env('PENNYHUNT_ALERT_EMAIL'),
    ],

    'moonshot' => [
        'enabled' => (bool) env('PENNYHUNT_MOONSHOT', true),
        'min_p' => (float) env('PENNYHUNT_MOONSHOT_MIN_P', 0.15),
        // Band cap: on BOTH validation runs the extreme-score tail was
        // pure chasers (run 35 conf>=0.25 deeply negative; run 36
        // moonshot p>=0.25: 0/9 hits). Extreme certainty = already moved.
        'max_p' => (float) env('PENNYHUNT_MOONSHOT_MAX_P', 0.25),
        'max_pre_run' => 0.15,
        'max_entry_price' => 5.0,
        'min_smallcap_rel' => -0.03,
        // Per-ticker refire cooldown (the lab used a 10-session cooldown).
        'cooldown_days' => 14,
        // Fixed-risk ticket per trade (fraction of account). The band is a
        // 1-in-N lottery profile: portfolio sims show it dies under
        // concentrated slots and survives under small tickets.
        'ticket_fraction' => (float) env('PENNYHUNT_MOONSHOT_TICKET', 0.03),
    ],

    'signals' => [
        // Composite score threshold above which a signal is fired.
        'fire_threshold' => (float) env('PENNYHUNT_SIGNAL_THRESHOLD', 0.65),
        // Don't re-fire for the same ticker within this many hours.
        'cooldown_hours' => 6,
        // Minimum mentions in the last hour before a ticker is even considered.
        'min_hourly_mentions' => 3,
        // Trailing baseline window (days) for z-scores.
        'baseline_days' => 30,

        /*
        | Market-confirmation gate (backtest audit v2/v3): buzz alone loses
        | money; buzz + low price + volume confirmation was the only net-
        | positive configuration (22% hit rate, 6.9x lift). Uses the latest
        | completed daily bar, so the volume read lags up to one session.
        | A ticker with no (or stale) bars is not fired — if we can't price
        | it we can't trade it.
        */
        'market_gate' => [
            'enabled' => (bool) env('PENNYHUNT_MARKET_GATE', true),
            // Only fire when the last close is at/below this price.
            'max_entry_price' => (float) env('PENNYHUNT_MAX_ENTRY_PRICE', 5.0),
            // Only fire when the latest bar's volume z-score (vs trailing 30
            // bars) is at/above this.
            'min_volume_z' => (float) env('PENNYHUNT_MIN_VOLUME_Z', 2.0),
            // Bars older than this many days are considered stale.
            'max_bar_age_days' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | NLP sidecar (phase 2b: FinBERT triage + LLM escalation)
    |--------------------------------------------------------------------------
    */
    'nlp' => [
        'sidecar_url' => env('PENNYHUNT_NLP_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | ML training (Phase C — GBM confidence model)
    |--------------------------------------------------------------------------
    | Python interpreter used by pennyhunt:train-gbm to run
    | scripts/train_gbm_model.py. Training is offline-only; the exported
    | artifact is evaluated in pure PHP at live scoring time.
    */
    'ml' => [
        'python' => env('PENNYHUNT_ML_PYTHON', base_path('.venv-ml/bin/python')),
    ],
];
