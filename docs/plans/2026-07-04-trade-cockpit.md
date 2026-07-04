# Trade Cockpit — Radar / Feed / Signals UI, UX & decision-support upgrade

> Created: 2026-07-04 · Status: **SHIPPED 2026-07-04** (all 3 phases; see
> "Implementation notes" at the bottom for deltas from the proposal)
> Companion docs: `docs/PLAN.md` §7, `docs/ARCHITECTURE.md`,
> `docs/audits/2026-07-04-phase-c-gbm.md`

## 0. The problem in one sentence

Pennyhunt today is a **monitoring dashboard** — it tells you a signal fired
and, days later, how it went; everything a trader needs *in between* (what
exactly to do at the open, where the stop is, how the position and its
social fuel are evolving, when to get out) lives in the operator's head.
This plan turns it into a **trade cockpit**: every signal becomes a managed
paper position with an explicit trade plan, live state, and evidence-backed
hold/exit guidance — the same v3 discipline and GBM confidence tiers the
backtest validated, surfaced at decision time.

## 1. Five-paragraph tradeoff analysis

**Paper-trade ledger vs. annotating signals in place.** The smallest change
is to keep bolting columns onto `signals` (it already has forward returns
and `graded_at`). But a trade is not a signal: it has an entry fill, a stop
level, a day counter, an exit fill and a lifecycle — and one signal may be
skipped (below tier) while another becomes a position. Mixing these
concerns has already made `GradeSignals` awkward, and the forward test —
our decision-gate evidence — needs clean per-trade accounting that mirrors
`backtest_events` semantics exactly. We choose a dedicated
**`signal_trades` table** (one row per signal that passes the trade tier,
entry/stop/exits computed by the same rules the Backtester uses), which
keeps `signals` as the immutable "what fired and why" record and makes the
forward-test scoreboard a trivial aggregate. Cost: one more table and a
grading job; benefit: the live system and the backtester finally speak the
same language.

**Live decision support: rule-engine guidance vs. ML-generated advice.**
Tempting to have the GBM (or an LLM) emit "hold/exit" recommendations. But
we have zero validated evidence for any exit policy other than v3 (10%
stop, no take, day-5 exit) — the backtest showed every take-profit variant
*loses* money. Advice beyond that discipline would be invented, and the UI
must never present invented numbers next to validated ones. So decision
support is a **deterministic checklist**: stop distance, days remaining,
calibrated P(hit) at entry vs. the tier threshold, mention-momentum decay,
volume confirmation, dilution red flags, regime warnings — each item
sourced from a validated feature with its backtest context shown
(winner/loser medians from run #32). The trader decides; the cockpit
arranges the evidence. ML-generated exit advice becomes a research item
only after minute-bar validation (Phase D).

**Price freshness: polling Yahoo vs. Polygon snapshots vs. real streaming.**
Open positions need fresher prices than "yesterday's close", but penny
pumps develop over hours and our exits are daily-OHLC rules — sub-second
data buys nothing and real-time OTC feeds are expensive and licensing-
heavy. The pragmatic middle: a **15-minute quote refresh for open trades
only** (a handful of symbols; Polygon Starter's snapshot endpoint covers
NMS, Yahoo's quote endpoint covers the rest, both effectively free at this
volume), pushed to the UI over the existing Reverb channel with an honest
freshness chip. Stop-hit detection stays *indicative* intraday (flagged in
UI) and *authoritative* on the completed daily bar, exactly like the
backtest, so live numbers and research numbers never diverge silently.

**Radar ranking: composite score vs. calibrated confidence.** The composite
(0.65 threshold) is what *fires* signals, but run #32 proved the GBM's
calibrated probability is what separates winners: top decile 13.1%
realized vs 0.5% bottom. Re-ranking the Radar by a number computed only at
fire time would require scoring every leaderboard row continuously —
cheap (the PHP evaluator is ~10ms) but semantically wrong for rows without
market-gate features. We therefore keep the leaderboard ordered by mention
heat, but **badge every row with its live tier** (TRADE ≥ 0.124 calibrated
/ WATCH / FADE, greyed when features are missing) and add a "forming"
panel of tickers approaching threshold, so attention flows to what is
about to matter rather than what already spiked. Full continuous scoring
is deferred until it can be done honestly (features cached per ticker-day).

**Frontend architecture: new pages vs. progressive enhancement.** A
ground-up "terminal" rebuild (dense grid layout, panels, workspaces) looks
attractive but risks a month of churn on plumbing while the actual edge
work (LLM retrain, forward test) is in flight. We keep the existing
Inertia + shadcn/ui page structure and **add one new page (signal detail
cockpit) plus targeted rebuilds** of Radar, Signals (→ blotter) and Feed,
sharing new primitives: `TierBadge`, `TradePlanCard`, `DecisionChecklist`,
`MiniSparkline`, `RegimeBanner`, and the existing `CandleChart`/`InfoTip`.
Recharts stays for stat charts, Lightweight Charts for price. This ships
in days per page, keeps the dark-terminal aesthetic consistent, and leaves
a later workspace layout open without locking anything in.

## 2. Backend: signal lifecycle & trade engine (the foundation)

### 2.1 `signal_trades` table (new)

One row per signal at/above the trade tier (calibrated p ≥ the active
model's `metrics.trade_tier.calibrated_p`, currently 0.124). Columns:

- `signal_id`, `ticker_id`, `tier` (trade/watch at creation)
- `status`: `pending_entry` (fired, waiting for next open) → `open` →
  `closed` | `cancelled` (no bar within 3 sessions)
- `entry_date`, `entry_price` (next session's official open, from the
  daily bar — identical to the Backtester)
- `stop_price` (entry × 0.90), `time_exit_date` (entry + 5 trading days)
- `exit_date`, `exit_price`, `exit_reason` (`stop` | `time` | `manual`),
  `exit_return`, `net_return` (5% friction, comparable to backtest)
- `confidence_at_entry`, `model_version`, `kelly_fraction` (suggested
  size from as-of tercile stats, liquidity-capped), `paper_size`
- `last_quote`, `last_quote_at`, `unrealized_return` (refresh job)

### 2.2 Jobs

- **`ManageSignalTrades`** (scheduled 05:10, after the bar sync; plus
  listener on SignalFired to create `pending_entry` rows): fills entries
  from the new day's open, walks completed bars with the *same pessimistic
  OHLC rules as `Backtester::simulateExit`* (gap-through-stop fills at
  open; stop before take on straddling bars), closes stop/time exits,
  computes net returns. Emits Reverb events (`trade.opened`,
  `trade.stopped`, `trade.closed`, `trade.stop_proximity`).
- **`RefreshOpenTradeQuotes`** (every 15 min, market hours): quotes for
  open positions only; sets `unrealized_return`, flags indicative
  stop-breach (UI warning — authoritative close still happens on the
  daily bar, keeping live == research semantics).
- Extend **`EvaluateAlertRules`**: new rule types `trade_opened`,
  `stop_proximity` (within 3% of stop), `time_exit_tomorrow`,
  `new_filing_on_open_position` (S-3/424B on a held ticker — the
  dilution ambush alert), `mention_collapse` (day-over-day mention z
  drop > 2σ on an open position).

### 2.3 Decision-support API (per signal)

`GET /signals/{id}/cockpit` (Inertia page props, same data):

- **Trade plan**: entry/stop/time-exit, tier, calibrated p, Kelly
  suggestion, liquidity cap, day counter.
- **Feature evidence**: all 23 features at fire time (already in
  `breakdown`), each annotated with run #32 winner/loser medians and a
  direction glyph ("this value looks like winners / losers / neutral").
- **Similar historical signals**: nearest neighbours from
  `backtest_events` (same entry-price bucket, volume-z band, confidence
  decile) with their outcome distribution — "signals like this hit 22%,
  median exit −4%, 12% did +100%". Honest priors instead of vibes.
- **Live social tape**: the posts driving the signal (mentions on
  signal day + since), LLM badges, author quality/track record.
- **Regime context**: VIX, site-buzz z, market_ret_5d with backtest
  P&L-by-bucket context.

## 3. Page-by-page plan

### 3.1 NEW: Signal cockpit (`/signals/{id}`)

The page you live on after a fire. Layout (top → bottom):

1. **Header strip**: symbol + name, TierBadge, calibrated p (with the
   tier threshold marked), composite, model version, fired-at, state
   chip (`pending_entry`/`open` day N of 5/`closed +x%`).
2. **Trade plan card + live P&L**: entry (or "enters at next open"),
   stop with distance-to-stop bar, time-exit date, unrealized/realized
   return (gross + net), Kelly-suggested size vs liquidity cap, freshness
   chip on the quote.
3. **Chart**: `CandleChart` with entry/stop/time-exit price lines and
   signal-day marker, mention-volume subgraph aligned on the same axis.
4. **Decision checklist** (deterministic, each row = evidence + verdict
   glyph): price vs stop; day counter; volume confirmation still present;
   mention momentum (streak alive? z decaying?); LLM tape quality (DD
   share vs hype share today vs signal day); pump suspicion; dilution
   flags (new filings since entry!); regime (VIX bucket, site-buzz).
5. **Similar-signals panel**: outcome histogram + table of the 20 nearest
   backtest neighbours.
6. **Social tape**: filterable post list (LLM type badges, author chips),
   live via Reverb.

### 3.2 Radar → attention triage

- Keep the leaderboard, add: **TierBadge column** (live-scored where the
  market gate features exist, greyed otherwise), volume-z chip, price +
  dollar-volume, dilution glyph (shelf/ATM), mention-streak flame,
  7-day mention sparkline per row.
- **RegimeBanner** across the top: VIX level/bucket, site-buzz z, IWM 5d,
  BTC 5d — colored by the backtest P&L context (e.g. amber at VIX ≥ 20),
  one glance answers "should I even be trading today?".
- **"Forming" panel**: tickers with composite 0.45–0.65 and rising
  mention z — the pre-signal pipeline, so fires are anticipated rather
  than discovered.
- **Open-positions rail**: compact cards of open trades (P&L, day, stop
  distance) — the Radar is the home page; positions must be visible
  without navigation.
- Row click → ticker page; signal chip click → cockpit.

### 3.3 Signals → trade blotter

Two tabs sharing the page:

- **Positions** (default): open + pending trades as rows — entry, stop,
  day N/5, unrealized net, stop-distance bar, tier, quick link to
  cockpit. Sorted by urgency (closest to stop / to time exit first).
- **History**: closed trades + non-tier signals. Columns: tier, entry →
  exit with reason glyph, net return, confidence at entry vs realized.
  Filters: tier, state, exit reason, date range.
- **Forward-test scoreboard** header (replaces the current 4 stat cards):
  cumulative net return + equity sparkline of the paper strategy, hit
  rate vs backtest expectation (22.9% for the tier), calibration check
  (avg p at entry vs realized hit rate), trade count toward the ≥50
  needed before trusting it. This is the decision-gate instrument.

### 3.4 Feed → the tape, filterable

- **LLM badges on every post**: type (DD/hype/news/technical), direction,
  conviction dots, pump-suspicion amber flag, off-topic posts excluded.
- **Author chips**: track-record score + n, pump-risk flag, verified (X).
- **Filter bar**: source, post type, min conviction, ticker (autocomplete),
  "only tickers with open positions", "only trade-tier signals' tickers".
- **Virtualized list** (the feed is the heaviest page; render ~30 rows,
  not 500) with live prepend via Reverb and a "N new posts" pill instead
  of layout-shifting auto-insert.
- Row affordances: click ticker chip → ticker page; expand post in place.

### 3.5 Ticker page (incremental)

Already strong after the hedge-fund upgrade. Additions: TierBadge +
latest calibrated p in the header when a recent signal exists; open-trade
banner (if we hold it) linking to the cockpit; "signal history" strip on
the candle chart (markers already exist — add outcome coloring).

### 3.6 Shared primitives & design system

- `TierBadge` (TRADE emerald / WATCH slate / FADE rose / n-a grey),
  `RegimeBanner`, `TradePlanCard`, `DecisionChecklist`, `StopDistanceBar`,
  `MiniSparkline` (SVG, no lib), `OutcomeHistogram`.
- Density: tabular numerals everywhere (`font-variant-numeric`), 4px
  vertical rhythm in tables, right-aligned numbers, muted-until-hover
  secondary actions.
- Command palette (⌘K): jump to ticker/signal, "open positions", "run
  backtest" — cheap with shadcn `Command`.
- Every derived number keeps an `InfoTip` with its backtest provenance —
  the discipline that nothing invented sits next to something validated.

## 4. Phasing & effort

| Phase | Scope | Est. |
|---|---|---|
| **1. Trade engine + cockpit** | `signal_trades` + ManageSignalTrades + quote refresh + cockpit page + blotter Positions tab | 2–3 days |
| **2. Radar triage + scoreboard** | TierBadge live scoring, RegimeBanner, forming panel, positions rail, forward-test scoreboard, History tab | 1–2 days |
| **3. Feed + alerts + polish** | Feed rebuild (badges/filters/virtualization), new alert rule types, similar-signals panel, ⌘K, ticker-page increments | 2 days |

Order rationale: phase 1 creates the *evidence machine* (forward test =
decision-gate input) — utility first; phases 2–3 compound attention and
convenience on top of it. Each phase ships independently; tests
(Pest feature tests on trade lifecycle vs simulated bars, mirroring
BacktesterTest) land with phase 1.

## 5. Explicitly out of scope (for now)

- Real brokerage / order routing (Alpaca paper API is Phase D's job).
- ML-generated exit advice (no validated policy beyond v3 — see §1 ¶2).
- Sub-15-min real-time quotes, workspace/multi-panel layout engine.
- Mobile-first redesign (desktop terminal is the use case).

## 6. Implementation notes (as shipped, 2026-07-04)

Everything above shipped in one pass; deltas and decisions worth recording:

- **Trade creation** — `OpenTradeForSignal` listener (auto-discovered) on
  `SignalFired` calls `TradeEngine::createForSignal`; only trade-tier
  signals get rows (no `watch` rows — the signal log already covers those).
  Signals with null confidence or no active tiered model open nothing.
- **Kelly suggestion** — half-Kelly from f\* = p − (1−p)/b where b is the
  win/|loss| payoff ratio measured on the active model's own training run
  (fired events ≥ tier raw p, net of 5% friction; cached daily), capped at
  10% equity. Advisory only; hidden when the run lacks enough wins.
- **Quote source** — Yahoo chart-meta `regularMarketPrice` for all symbols
  (Polygon snapshot deferred; one source is enough at this volume).
- **Alerts** — implemented as *system-generated* `alert_events`
  (`alert_rule_id` made nullable, new `kind` + `signal_trade_id` columns)
  rather than user-configured rule kinds: `trade_stop_proximity`,
  `trade_time_exit_next`, `trade_new_filing`, `trade_mention_collapse`.
  Surfaced as chips on the blotter; deduped per trade+kind+day.
- **Feed** — kept 50-row pagination instead of a virtualization lib
  (measured render is fine; revisit only if the page actually gets slow).
  Off-topic (LLM-flagged) posts are excluded server-side.
- **Cockpit checklist** — 8 evidence rows against run #32 winner/loser
  medians (94 winners / 625 losers); LLM rows shown without medians until
  a high-coverage retrain lands. `CandleChart` gained a `levels` prop
  (entry/stop price lines).
- **Deferred from the proposal**: ⌘K command palette, per-row leaderboard
  sparklines + dilution glyphs, ticker-page open-trade banner, blotter
  equity sparkline, live per-row GBM scoring on the Radar (needs cached
  per-ticker-day features to be honest).
- **Tests**: `TradeEngineTest` (entry fill, gap-through-stop, entry-day
  stop, time exit, cancel), `TradeAlertsTest`, `SignalCockpitPageTest`.
