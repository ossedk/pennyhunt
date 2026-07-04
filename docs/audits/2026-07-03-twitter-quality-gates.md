# Twitter quality gates — 2026-07-03

## Problem

The first unfiltered Twitter run showed exactly the two failure modes the
plan predicted for cashtag feeds:

1. **Zero-engagement bot noise** — 279 of 300 tweets (93%) had fewer than
   5 likes.
2. **Crypto symbol collisions** — tokens/airdrops sharing the $symbol of a
   tracked stock (e.g. "$XYZ airdrop live, connect wallet"), which would
   count as ticker mentions and poison mention velocity — the core input to
   the signal engine.

## Three-layer defense

### Layer 1 — pay-side filter (search query)
`min_faves:5` appended to every cashtag search (`PENNYHUNT_TWITTER_MIN_LIKES`,
default 5). Since the actor bills per dataset item, filtering server-side
means we stop paying for bot junk entirely. A matching ingest-side like floor
in `TwitterIngestor` guards against filter drift.

### Layer 2 — heuristic spam scanner (free, instant)
`App\Services\Nlp\TweetSpamScanner`, applied at ingestion:

| Rule | Reason code |
|---|---|
| Crypto vocabulary (airdrop, presale, mint, connect wallet, tokenomics, memecoin, …) with NO equity context | `crypto_offtopic` |
| Ethereum contract address (`0x` + 40 hex) | `crypto_contract_address` |
| More than 6 cashtags in one tweet | `cashtag_stuffing` |
| `t.me/` invite link without equity context | `promo_funnel_link` |

Design notes:
- **Word-boundary matching**, not substring — "rug" must not flag "drug"
  (biotech penny stocks talk about drugs constantly).
- **Equity-context rescue**: stock vocabulary (shares, float, NASDAQ, SEC
  filing, short interest, squeeze, earnings, …) suppresses the crypto verdict,
  so "shorts trapped, this squeeze could mint millionaires" survives.
- Flagged tweets are dropped before storage — no raw_post, no mentions.

### Layer 3 — LLM off-topic verdict (subtle cases)
`LlmPostClassifier` prompt extended with an `off_topic` boolean ("crypto
token/coin/airdrop that merely shares the $SYMBOL of a stock, wallet/presale
promos, bot spam unrelated to equities"). Stored on the new
`post_sentiments.llm_off_topic` column. When true **on a twitter post**, the
post's `post_ticker_mentions` are deleted so it cannot contribute to mention
counts, breadth, or streaks. Reddit posts keep their mentions (subreddit
context makes crypto collisions rare there; a false deletion would be worse).

The verified-voices panel on the ticker page additionally filters
`score >= min_likes` and excludes `llm_off_topic = true`.

## Retroactive cleanup

The unfiltered first run was purged with the same rules: **300 → 18 tweets
survived** (279 below the like floor, 2 cashtag stuffing, 1 crypto
off-topic). Mentions and sentiments cascade-deleted.

## Verification

- 104 backend tests pass (360 assertions), including new coverage:
  `TweetSpamScannerTest` (6 cases incl. equity-context rescue),
  low-like + crypto-spam skip in `PollTwitterViaApifyTest`,
  off-topic mention deletion in `LlmPostClassifierTest`.
- Migration `add_llm_off_topic_to_post_sentiments` applied.

## Round 2 — the $WEN memecoin leak (same day)

The verified-voices panel for Wendy's ($WEN) still showed tweets about the
*WEN memecoin* (a Solana token whose lore riffs on "wen airdrop"). Two gaps:

1. **Heuristics missed crypto-cashtag threads**: "$SOL to 500$ … $WEN to
   0.01$ $BTC to 150k$" has no airdrop vocabulary. Added: (a) a curated
   `CRYPTO_SYMBOLS` list (BTC, ETH, SOL, JUP, … — deliberately excluding
   symbols that collide with liquid US tickers like LTC/ETC/APT); 2+ crypto
   cashtags with no equity context ⇒ `crypto_cashtags`. (b) Solana/base58
   contract addresses (32–44 chars) now count as `crypto_contract_address`
   alongside 0x addresses. (c) "token", "pumpfun", "holder count",
   "on-chain" added to crypto vocabulary; "ticker" removed from equity
   rescue terms (crypto promoters say "the ticker $WEN" constantly).
2. **The LLM couldn't tell the coin from the stock** without knowing what
   the symbol refers to. `LlmPostClassifier` now prepends ticker context
   ("$WEN = Wendy's Co (XNAS), market cap $1.6B") to every classified post,
   the prompt enumerates memecoin tells (CA/contract addresses, PumpFun/
   Moby/DEX platforms, CTO, airdrop, holder lore, crypto KOLs, million-MC
   talk for billion-dollar companies), and OpenAI reasoning effort was
   raised from `minimal` to `low` — at minimal effort the model ignored
   the market-cap inconsistency entirely.

All stored tweets were re-scanned (10 more purged heuristically) and
re-classified with context; off-topic tweets had their ticker mentions
deleted. Tweets also now skip the 40-char LLM length floor so every
cashtag tweet gets the off_topic check.

Residual risk: bare one-liners with zero tells ("$WEN is Biblical") are
genuinely undecidable and stay visible — acceptable, since they carry no
memecoin signal either.

## Cost effect

Search-side `min_faves:5` should cut per-item billing by roughly the same
93% junk share observed in run 1 — the hourly poller now costs close to the
per-query floor (~$0.05/run at 3 queries) unless something is genuinely hot.
