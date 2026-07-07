import { Head, Link } from '@inertiajs/react';
import { ExternalLink, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import { HypeSwarm } from '@/components/pennyhunt/hype-swarm';
import { LiveDeskCard } from '@/components/pennyhunt/live-desk';
import { live as signalLive, swarm as signalSwarm } from '@/routes/signals';
import { MarketStatusBadge, relativeTime, TierBadge, TradeStatusBadge } from '@/components/pennyhunt/badges';
import type { MarketStatus } from '@/components/pennyhunt/badges';
import { CandleChart, IntervalToggle } from '@/components/pennyhunt/candle-chart';
import type { ChartInterval, ChartLevel, ChartMarker, OhlcBar } from '@/components/pennyhunt/candle-chart';
import { InfoTip } from '@/components/pennyhunt/info-tip';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { signals } from '@/routes';
import { bars as signalBars, intraday as signalIntraday } from '@/routes/signals';
import { show as tickerShow } from '@/routes/tickers';

type Trade = {
    id: number;
    status: string;
    tier: string;
    confidence_at_entry: number | null;
    entry_date: string | null;
    entry_price: number | null;
    stop_price: number | null;
    time_exit_date: string | null;
    exit_date: string | null;
    exit_price: number | null;
    exit_reason: string | null;
    exit_return: number | null;
    net_return: number | null;
    kelly_fraction: number | null;
    last_quote: number | null;
    last_quote_at: string | null;
    unrealized_return: number | null;
    holding_day: number | null;
};

type SignalDetail = {
    id: number;
    symbol: string;
    name: string | null;
    exchange: string | null;
    fired_at: string;
    score: number;
    confidence: number | null;
    model_version: string | null;
    state: string;
    breakdown: {
        components?: Record<string, number>;
        market_gate?: {
            passes: boolean;
            close: number | null;
            volume_z: number | null;
            pre_return_3d: number | null;
            dollar_volume: number | null;
        } | null;
        intel?: Record<string, number | boolean | null>;
        llm?: Record<string, number | null>;
        inputs?: {
            mention_count?: number;
            unique_authors?: number;
            weighted_sentiment?: number;
            zscore_mentions?: number;
        };
    } | null;
    forward_return_1d: number | null;
    forward_return_3d: number | null;
    forward_return_5d: number | null;
    llm_brief: {
        summary: string;
        watch_for: string[];
        invalidation: string;
        risk: string;
    } | null;
};

type ProfileSide = Record<string, number>;

type Post = {
    id: number;
    kind: string;
    title: string | null;
    body: string;
    permalink: string | null;
    score: number;
    posted_at: string;
    source: { key: string; name: string };
    author: { username: string; karma: number | null; pump_risk_score: number | null } | null;
    sentiment: {
        lexicon_score: number | null;
        llm_direction: string | null; // bullish | bearish | neutral
        llm_post_type: string | null;
        llm_conviction: number | null;
        llm_pump_suspicion: number | null;
    } | null;
};

type Props = {
    signal: SignalDetail;
    trade: Trade | null;
    tradeTier: { raw_p: number; calibrated_p: number } | null;
    winnerProfile: { winners: ProfileSide; losers: ProfileSide } | null;
    similar: {
        n: number;
        hit_rate: number;
        median_exit: number;
        p90_exit: number;
        share_100pct: number;
        stop_rate: number;
        examples: { symbol: string; day: string; exit_return: number }[];
    } | null;
    intelToday: Record<string, number | boolean | null>;
    mentionCurve: { day: string; mentions: number; authors: number; zscore: number | null; sentiment: number | null }[];
    filingsSinceFire: { form: string; filed_at: string }[];
    posts: Post[];
    marketStatus: MarketStatus | null;
    extendedQuote: {
        price: number;
        change_pct: number | null;
        session: string;
        as_of: string;
        prev_close: number | null;
    } | null;
};

const pct = (v: number | null | undefined, digits = 1) =>
    v === null || v === undefined ? '—' : `${v >= 0 ? '+' : ''}${(v * 100).toFixed(digits)}%`;

const usd = (v: number | null | undefined) =>
    v === null || v === undefined ? '—' : `$${v >= 1 ? v.toFixed(2) : v.toFixed(4)}`;

const num = (v: number | null | undefined, digits = 1) => (v === null || v === undefined ? '—' : v.toFixed(digits));

const returnColor = (v: number | null | undefined) =>
    v === null || v === undefined ? 'text-muted-foreground' : v > 0 ? 'text-emerald-400' : v < 0 ? 'text-rose-400' : 'text-muted-foreground';

export default function SignalCockpit({
    signal,
    trade,
    tradeTier,
    winnerProfile,
    similar,
    intelToday,
    mentionCurve,
    filingsSinceFire,
    posts,
    marketStatus,
    extendedQuote,
}: Props) {
    return (
        <>
            <Head title={`${signal.symbol} signal`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {/* ── Header ────────────────────────────────────────────── */}
                <div className="flex flex-wrap items-center gap-3">
                    <Link href={tickerShow(signal.symbol)} className="font-mono text-2xl font-bold hover:underline">
                        {signal.symbol}
                    </Link>
                    <div className="min-w-0">
                        <p className="truncate text-sm text-muted-foreground">
                            {signal.name ?? '—'}
                            {signal.exchange ? ` · ${signal.exchange}` : ''}
                        </p>
                    </div>
                    <TierBadge confidence={signal.confidence} threshold={tradeTier?.calibrated_p ?? null} />
                    {trade && <TradeStatusBadge status={trade.status} />}
                    {extendedQuote && (
                        <Badge variant="outline" className="font-mono text-xs">
                            {extendedQuote.session === 'early_hours'
                                ? 'pre-mkt'
                                : extendedQuote.session === 'after_hours'
                                  ? 'after-hrs'
                                  : 'live'}{' '}
                            ${extendedQuote.price}
                            {extendedQuote.change_pct !== null && (
                                <span className={extendedQuote.change_pct >= 0 ? 'text-emerald-400' : 'text-rose-400'}>
                                    {' '}
                                    {extendedQuote.change_pct >= 0 ? '+' : ''}
                                    {(extendedQuote.change_pct * 100).toFixed(1)}%
                                </span>
                            )}
                        </Badge>
                    )}
                    <div className="ml-auto flex items-center gap-3">
                        <MarketStatusBadge market={marketStatus} />
                        <span className="text-xs text-muted-foreground">
                            fired {relativeTime(signal.fired_at)}
                            {signal.model_version ? ` · ${signal.model_version}` : ''}
                        </span>
                    </div>
                </div>

                {/* ── KPI strip ─────────────────────────────────────────── */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <Kpi
                        label="Model probability"
                        value={signal.confidence !== null ? `${(signal.confidence * 100).toFixed(1)}%` : '—'}
                        hint={
                            tradeTier
                                ? `P(≥ +30% in 5 sessions). Trade tier fires at ≥ ${(tradeTier.calibrated_p * 100).toFixed(1)}%.`
                                : 'P(≥ +30% in 5 sessions). No active tiered model.'
                        }
                        accent={
                            signal.confidence !== null && tradeTier && signal.confidence >= tradeTier.calibrated_p
                                ? 'text-emerald-400'
                                : undefined
                        }
                    />
                    <Kpi label="Composite buzz" value={`${(signal.score * 100).toFixed(0)}`} hint="Mention acceleration + breadth + sentiment + cross-source, 0–100." />
                    <Kpi
                        label={trade?.status === 'closed' ? 'Realized (net)' : 'Unrealized'}
                        value={trade?.status === 'closed' ? pct(trade.net_return) : pct(trade?.unrealized_return)}
                        accent={returnColor(trade?.status === 'closed' ? trade.net_return : trade?.unrealized_return)}
                        hint={
                            trade?.status === 'closed'
                                ? 'Exit return minus 5% friction (slippage + fees), the backtest convention.'
                                : trade?.last_quote_at
                                  ? `Last quote ${relativeTime(trade.last_quote_at)} (15-min indicative refresh).`
                                  : 'Updates every 15 min during market hours once the position is open.'
                        }
                    />
                    <Kpi
                        label="Suggested size"
                        value={trade?.kelly_fraction !== null && trade?.kelly_fraction !== undefined ? `${(trade.kelly_fraction * 100).toFixed(1)}%` : '—'}
                        hint="Half-Kelly from the model's calibrated probability and the backtest payoff ratio, capped at 10% of equity. Advisory only."
                    />
                    <Kpi
                        label="Holding day"
                        value={
                            trade?.status === 'open' && trade.holding_day !== null
                                ? `${trade.holding_day} / 5`
                                : trade?.status === 'closed'
                                  ? `exit: ${trade.exit_reason ?? '—'}`
                                  : '—'
                        }
                        hint="The validated discipline holds at most 5 sessions, then exits at the close."
                    />
                </div>

                <LiveDeskCard url={signalLive(signal.id).url} />

                {signal.llm_brief && <SignalBriefCard brief={signal.llm_brief} />}

                <div className="grid gap-4 xl:grid-cols-3">
                    {/* ── Left 2/3: plan, chart, checklist, analogs ─────── */}
                    <div className="flex flex-col gap-4 xl:col-span-2">
                        <TradePlanCard trade={trade} tradeTier={tradeTier} confidence={signal.confidence} />
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm">
                                    Price action{' '}
                                    <span className="font-normal text-muted-foreground">— fire, entry and stop on the daily tape</span>
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <CockpitChart signalId={signal.id} trade={trade} firedAt={signal.fired_at} />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-1.5 text-sm">
                                    Crowd momentum
                                    <InfoTip>
                                        Every particle is crowd attention; color is sentiment, labeled comets are
                                        identifiable loud authors (amber = ranked Voices). The core grows with the
                                        mention z-score — when it crosses the dashed ring the crowd hit critical
                                        mass. The playback runs from 48h before the fire, so you watch the momentum
                                        build; live signals refresh hourly.
                                    </InfoTip>
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <HypeSwarm url={signalSwarm(signal.id).url} height={400} />
                            </CardContent>
                        </Card>
                        <DecisionChecklist signal={signal} winnerProfile={winnerProfile} />
                        <SimilarSignalsCard similar={similar} />
                    </div>

                    {/* ── Right 1/3: context rails ──────────────────────── */}
                    <div className="flex flex-col gap-4">
                        <RegimeCard intelToday={intelToday} />
                        <DilutionCard intelToday={intelToday} filings={filingsSinceFire} />
                        <MentionCurveCard curve={mentionCurve} firedAt={signal.fired_at} />
                        <TapeCard posts={posts} />
                    </div>
                </div>
            </div>
        </>
    );
}

/** LLM "what to look for" note — generated at fire time from the exact features the model scored. */
function SignalBriefCard({ brief }: { brief: NonNullable<SignalDetail['llm_brief']> }) {
    return (
        <Card className="border-emerald-500/20">
            <CardHeader>
                <CardTitle className="flex items-center gap-1.5 text-sm">
                    <Sparkles className="size-4 text-emerald-400" />
                    What to look for
                    <InfoTip>
                        LLM-written from the signal's own feature breakdown, the trade discipline, and recent
                        posts/headlines — it can only reference facts the model actually saw.
                    </InfoTip>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <p className="text-sm leading-relaxed">{brief.summary}</p>
                {brief.watch_for.length > 0 && (
                    <div className="grid gap-1.5 sm:grid-cols-2">
                        {brief.watch_for.map((item, i) => (
                            <div key={i} className="flex items-start gap-2 rounded-md border border-border/60 px-2.5 py-1.5 text-xs">
                                <span className="mt-0.5 inline-block size-1.5 shrink-0 rounded-full bg-emerald-400" />
                                {item}
                            </div>
                        ))}
                    </div>
                )}
                <div className="flex flex-col gap-1 text-xs text-muted-foreground">
                    {brief.invalidation && (
                        <p>
                            <span className="font-medium text-rose-400">Invalidation:</span> {brief.invalidation}
                        </p>
                    )}
                    {brief.risk && (
                        <p>
                            <span className="font-medium text-amber-400">Biggest risk:</span> {brief.risk}
                        </p>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function Kpi({ label, value, hint, accent }: { label: string; value: string; hint?: string; accent?: string }) {
    return (
        <Card className="py-3">
            <CardContent className="px-4">
                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                    {label}
                    {hint && <InfoTip>{hint}</InfoTip>}
                </p>
                <p className={cn('mt-1 font-mono text-xl font-semibold', accent)}>{value}</p>
            </CardContent>
        </Card>
    );
}

/* ── Trade plan ─────────────────────────────────────────────────────── */

function TradePlanCard({
    trade,
    tradeTier,
    confidence,
}: {
    trade: Trade | null;
    tradeTier: { raw_p: number; calibrated_p: number } | null;
    confidence: number | null;
}) {
    if (trade === null) {
        const belowTier = confidence !== null && tradeTier !== null && confidence < tradeTier.calibrated_p;

        return (
            <Card>
                <CardHeader>
                    <CardTitle className="text-sm">Trade plan</CardTitle>
                </CardHeader>
                <CardContent>
                    <p className="text-sm text-muted-foreground">
                        {belowTier
                            ? `No paper trade — the model probability is below the validated trade tier (${(
                                  (tradeTier?.calibrated_p ?? 0) * 100
                              ).toFixed(1)}%). Watch tier: monitor, don't chase.`
                            : 'No paper trade for this signal (fired before the trade engine existed, or no tiered model was active).'}
                    </p>
                </CardContent>
            </Card>
        );
    }

    const stopDistance =
        trade.last_quote !== null && trade.stop_price !== null && trade.last_quote > 0
            ? (trade.last_quote - trade.stop_price) / trade.last_quote
            : null;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-sm">
                    Trade plan
                    <span className="font-normal text-muted-foreground">— validated v3 discipline, executed automatically</span>
                    {stopDistance !== null && stopDistance <= 0.03 && trade.status === 'open' && (
                        <Badge variant="outline" className="border-rose-500/50 bg-rose-500/10 font-mono text-[10px] text-rose-300">
                            {stopDistance <= 0 ? 'BELOW STOP (awaiting daily bar)' : `stop ${(stopDistance * 100).toFixed(1)}% away`}
                        </Badge>
                    )}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
                    <PlanItem label="Entry" value={usd(trade.entry_price)} sub={trade.entry_date ?? 'next session open'} />
                    <PlanItem
                        label="Stop (-10%)"
                        value={usd(trade.stop_price)}
                        sub="gap-through fills at the open"
                        accent="text-rose-400"
                    />
                    <PlanItem
                        label="Time exit"
                        value={trade.time_exit_date ?? 'day 5'}
                        sub="close of the 5th session"
                    />
                    <PlanItem label="Last quote" value={usd(trade.last_quote)} sub={trade.last_quote_at ? relativeTime(trade.last_quote_at) : '—'} />
                    <PlanItem
                        label={trade.status === 'closed' ? 'Exit' : 'P&L (unrealized)'}
                        value={trade.status === 'closed' ? usd(trade.exit_price) : pct(trade.unrealized_return)}
                        sub={trade.status === 'closed' ? `${trade.exit_date ?? ''} · ${trade.exit_reason ?? ''}` : 'vs entry'}
                        accent={returnColor(trade.status === 'closed' ? trade.exit_return : trade.unrealized_return)}
                    />
                    <PlanItem
                        label="Net result"
                        value={trade.status === 'closed' ? pct(trade.net_return) : '—'}
                        sub="after 5% friction"
                        accent={returnColor(trade.net_return)}
                    />
                </div>
            </CardContent>
        </Card>
    );
}

function PlanItem({ label, value, sub, accent }: { label: string; value: string; sub?: string; accent?: string }) {
    return (
        <div>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className={cn('font-mono font-semibold', accent)}>{value}</p>
            {sub && <p className="text-[11px] text-muted-foreground">{sub}</p>}
        </div>
    );
}

/* ── Chart ──────────────────────────────────────────────────────────── */

type SignalBarsResponse = {
    fired_date: string;
    bars: OhlcBar[];
    entry_date: string | null;
    entry: number | null;
    stop_level: number | null;
    time_exit_date: string | null;
};

type IntradayResponse = {
    bars: OhlcBar[];
    markers: { time: number; label: string; color: string }[];
};

function CockpitChart({ signalId, trade }: { signalId: number; trade: Trade | null; firedAt: string }) {
    const [interval, setInterval] = useState<ChartInterval>('1h');
    const [daily, setDaily] = useState<SignalBarsResponse | null>(null);
    const [intraday, setIntraday] = useState<Partial<Record<'1h' | '5m', IntradayResponse>>>({});
    const [error, setError] = useState(false);

    // Daily payload always loads (it carries entry/stop levels for every view).
    useEffect(() => {
        let cancelled = false;

        fetch(signalBars(signalId).url, { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : Promise.reject(new Error(String(res.status)))))
            .then((json: SignalBarsResponse) => {
                if (!cancelled) {
                    setDaily(json);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setError(true);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [signalId]);

    // Intraday payloads load on demand, once per interval.
    useEffect(() => {
        if (interval === '1d' || intraday[interval] !== undefined) {
            return;
        }

        let cancelled = false;
        const key = interval;

        fetch(signalIntraday(signalId, { query: { interval: key } }).url, { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : Promise.reject(new Error(String(res.status)))))
            .then((json: IntradayResponse) => {
                if (!cancelled) {
                    setIntraday((prev) => ({ ...prev, [key]: json }));
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setError(true);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [interval, signalId, intraday]);

    if (error) {
        return <p className="py-8 text-center text-sm text-muted-foreground">Could not load price data.</p>;
    }

    if (daily === null || (interval !== '1d' && intraday[interval] === undefined)) {
        return <p className="py-8 text-center text-sm text-muted-foreground">Loading price data…</p>;
    }

    const entry = trade?.entry_price ?? daily.entry;
    const stop = trade?.stop_price ?? daily.stop_level;

    const levels: ChartLevel[] = [
        ...(entry !== null ? [{ value: entry, label: 'entry', color: '#10b981' }] : []),
        ...(stop !== null ? [{ value: stop, label: '-10% stop', color: '#ef4444' }] : []),
    ];

    let bars: OhlcBar[];
    let markers: ChartMarker[];

    if (interval === '1d') {
        const firedBarDate = [...daily.bars].reverse().find((bar) => bar.date <= daily.fired_date)?.date ?? daily.fired_date;

        bars = daily.bars;
        markers = [
            { date: firedBarDate, label: 'fired', color: '#f59e0b' },
            ...(trade?.entry_date ?? daily.entry_date
                ? [{ date: (trade?.entry_date ?? daily.entry_date)!, label: 'entry', color: '#10b981' }]
                : []),
            ...(trade?.exit_date ? [{ date: trade.exit_date, label: `exit (${trade.exit_reason})`, color: '#f43f5e' }] : []),
        ];
    } else {
        const payload = intraday[interval]!;

        bars = payload.bars;
        markers = payload.markers;
    }

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between">
                <span className="text-[11px] text-muted-foreground">
                    {interval === '1d'
                        ? 'Daily bars — the trade discipline plays out on this resolution.'
                        : interval === '1h'
                          ? 'Hourly bars incl. pre/after-market — the fire is marked at its exact hour.'
                          : '5-minute tape around the fire — 3 days before to 10 days after.'}
                </span>
                <IntervalToggle value={interval} onChange={setInterval} />
            </div>
            <CandleChart
                key={interval}
                bars={bars}
                markers={markers}
                levels={levels}
                height={320}
                defaultRange="All"
                intraday={interval !== '1d'}
            />
        </div>
    );
}

/* ── Decision checklist ─────────────────────────────────────────────── */

type ChecklistRow = {
    label: string;
    tip: string;
    value: number | null;
    winner: number | null;
    loser: number | null;
    format: (v: number) => string;
};

function DecisionChecklist({ signal, winnerProfile }: { signal: SignalDetail; winnerProfile: Props['winnerProfile'] }) {
    if (!winnerProfile) {
        return null;
    }

    const gate = signal.breakdown?.market_gate;
    const inputs = signal.breakdown?.inputs;
    const intel = signal.breakdown?.intel;
    const llm = signal.breakdown?.llm;
    const w = winnerProfile.winners;
    const l = winnerProfile.losers;

    const rows: ChecklistRow[] = [
        {
            label: 'Volume spike (z)',
            tip: 'Volume z-score vs the ticker\'s own 30-day baseline at fire time. Winners spike harder.',
            value: gate?.volume_z ?? null,
            winner: w.median_volume_z ?? null,
            loser: l.median_volume_z ?? null,
            format: (v) => v.toFixed(1),
        },
        {
            label: 'Mention spike (z)',
            tip: 'Social mention z-score. The strongest single separator in the 24-month backtest: winners median 7.5σ vs losers 5.5σ.',
            value: inputs?.zscore_mentions ?? null,
            winner: w.median_mention_z ?? null,
            loser: l.median_mention_z ?? null,
            format: (v) => v.toFixed(1),
        },
        {
            label: 'Pre-move (3d)',
            tip: 'Price run-up in the 3 sessions before the fire. Winners were already moving (+36% median) — momentum begets momentum in penny land.',
            value: gate?.pre_return_3d ?? null,
            winner: w.median_pre_return_3d ?? null,
            loser: l.median_pre_return_3d ?? null,
            format: (v) => pct(v, 0),
        },
        {
            label: 'Dollar volume',
            tip: 'Daily traded value at fire time. Winners traded ~4x more ($108M vs $28M median) — liquidity validates the move and makes the exit executable.',
            value: gate?.dollar_volume ?? null,
            winner: w.median_dollar_volume ?? null,
            loser: l.median_dollar_volume ?? null,
            format: (v) => `$${Intl.NumberFormat('en', { notation: 'compact' }).format(v)}`,
        },
        {
            label: 'Sentiment',
            tip: 'Weighted social sentiment. Counter-intuitive: winners had slightly LOWER sentiment than losers — euphoria is not edge.',
            value: inputs?.weighted_sentiment ?? null,
            winner: w.median_sentiment ?? null,
            loser: l.median_sentiment ?? null,
            format: (v) => v.toFixed(2),
        },
        {
            label: 'ATM filed (90d)',
            tip: 'Recent at-the-market offering on file. Perverse but real: winners had a HIGHER ATM rate (45% vs 30%) — dilution machines are the ones that get pumped.',
            value: intel?.atm_filed_90d != null ? Number(intel.atm_filed_90d) : null,
            winner: w.atm_filed_90d_rate ?? null,
            loser: l.atm_filed_90d_rate ?? null,
            format: (v) => (v === 1 ? 'yes' : v === 0 ? 'no' : `${(v * 100).toFixed(0)}%`),
        },
        {
            label: 'LLM pump suspicion',
            tip: 'Average pump-suspicion score across LLM-classified posts today (0–1). High values mean the tape smells coordinated.',
            value: llm?.llm_pump_suspicion ?? null,
            winner: null,
            loser: null,
            format: (v) => `${(v * 100).toFixed(0)}%`,
        },
        {
            label: 'LLM conviction',
            tip: 'Average conviction of bullish LLM-classified posts today (0–1). Genuine DD reads different from drive-by hype.',
            value: llm?.llm_conviction ?? null,
            winner: null,
            loser: null,
            format: (v) => `${(v * 100).toFixed(0)}%`,
        },
    ];

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">
                    Decision evidence{' '}
                    <span className="font-normal text-muted-foreground">
                        — this signal vs the median winner / loser from the 24-month backtest (94 winners, 625 losers)
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="flex flex-col divide-y divide-border/60">
                    {rows.map((row) => (
                        <ChecklistLine key={row.label} row={row} />
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

function ChecklistLine({ row }: { row: ChecklistRow }) {
    const { value, winner, loser } = row;

    let verdict: 'winner' | 'loser' | 'mixed' | null = null;

    if (value !== null && winner !== null && loser !== null && winner !== loser) {
        const higherIsWinner = winner > loser;
        const beatsWinner = higherIsWinner ? value >= winner : value <= winner;
        const beatsLoser = higherIsWinner ? value > loser : value < loser;
        verdict = beatsWinner ? 'winner' : beatsLoser ? 'mixed' : 'loser';
    }

    return (
        <div className="flex items-center gap-3 py-2 text-sm">
            <span
                className={cn(
                    'size-2 shrink-0 rounded-full',
                    verdict === 'winner' && 'bg-emerald-500',
                    verdict === 'mixed' && 'bg-amber-500',
                    verdict === 'loser' && 'bg-rose-500',
                    verdict === null && 'bg-zinc-600',
                )}
            />
            <span className="flex w-44 shrink-0 items-center gap-1 text-muted-foreground">
                {row.label}
                <InfoTip>{row.tip}</InfoTip>
            </span>
            <span className="font-mono font-semibold">{value !== null ? row.format(value) : '—'}</span>
            {winner !== null && loser !== null && (
                <span className="ml-auto font-mono text-xs text-muted-foreground">
                    W <span className="text-emerald-400/80">{row.format(winner)}</span>
                    {' · '}L <span className="text-rose-400/80">{row.format(loser)}</span>
                </span>
            )}
        </div>
    );
}

/* ── Similar signals ────────────────────────────────────────────────── */

function SimilarSignalsCard({ similar }: { similar: Props['similar'] }) {
    if (!similar) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">
                    Historical analogs{' '}
                    <span className="font-normal text-muted-foreground">
                        — {similar.n} backtest signals in the same price bucket and volume band
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <PlanItem label="Hit ≥ +30%" value={pct(similar.hit_rate, 1)} accent="text-emerald-400" />
                    <PlanItem label="Median exit" value={pct(similar.median_exit)} accent={returnColor(similar.median_exit)} />
                    <PlanItem label="P90 exit" value={pct(similar.p90_exit)} accent={returnColor(similar.p90_exit)} />
                    <PlanItem label="≥ +100%" value={pct(similar.share_100pct, 1)} />
                    <PlanItem label="Stopped out" value={pct(similar.stop_rate, 0)} accent="text-rose-400" />
                </div>
                {similar.examples.length > 0 && (
                    <p className="text-xs text-muted-foreground">
                        Best analogs:{' '}
                        {similar.examples.map((e, i) => (
                            <span key={`${e.symbol}-${e.day}`} className="font-mono">
                                {i > 0 && ' · '}
                                {e.symbol} <span className={returnColor(e.exit_return)}>{pct(e.exit_return, 0)}</span>
                            </span>
                        ))}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

/* ── Right rail cards ───────────────────────────────────────────────── */

function RegimeCard({ intelToday }: { intelToday: Props['intelToday'] }) {
    const vix = intelToday.vix as number | null;
    const marketRet = intelToday.market_ret_5d as number | null;
    const btcRet = intelToday.btc_ret_5d as number | null;
    const siteZ = intelToday.site_mention_z as number | null;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-1 text-sm">
                    Market regime (today)
                    <InfoTip>The same macro features the model sees. Penny-stock pumps live and die with risk appetite: low VIX and a rising tape keep the game alive.</InfoTip>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="grid grid-cols-2 gap-3">
                    <PlanItem
                        label="VIX"
                        value={num(vix)}
                        accent={vix !== null ? (vix < 20 ? 'text-emerald-400' : vix < 28 ? 'text-amber-400' : 'text-rose-400') : undefined}
                        sub={vix !== null ? (vix < 20 ? 'risk-on' : vix < 28 ? 'jumpy' : 'risk-off') : undefined}
                    />
                    <PlanItem label="S&P 5d" value={pct(marketRet)} accent={returnColor(marketRet)} />
                    <PlanItem label="BTC 5d" value={pct(btcRet)} accent={returnColor(btcRet)} sub="degen appetite proxy" />
                    <PlanItem label="Site buzz (z)" value={num(siteZ)} sub="platform-wide mention level" />
                </div>
            </CardContent>
        </Card>
    );
}

function DilutionCard({
    intelToday,
    filings,
}: {
    intelToday: Props['intelToday'];
    filings: Props['filingsSinceFire'];
}) {
    const shortRatio = intelToday.short_ratio as number | null;
    const shareGrowth = intelToday.share_growth_12m as number | null;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-1 text-sm">
                    Dilution & short flow
                    <InfoTip>As-of today. A fresh shelf or ATM takedown mid-trade is the classic rug: the company sells into the pump you're holding.</InfoTip>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <div className="grid grid-cols-2 gap-3">
                    <PlanItem label="Short ratio" value={shortRatio !== null ? `${(shortRatio * 100).toFixed(0)}%` : '—'} sub="of daily volume" />
                    <PlanItem label="Share growth 12m" value={pct(shareGrowth, 0)} sub="dilution rate" />
                    <PlanItem label="Active shelf" value={intelToday.active_shelf ? 'yes' : 'no'} accent={intelToday.active_shelf ? 'text-amber-400' : undefined} />
                    <PlanItem label="ATM filed 90d" value={intelToday.atm_filed_90d ? 'yes' : 'no'} accent={intelToday.atm_filed_90d ? 'text-amber-400' : undefined} />
                </div>
                <div>
                    <p className="mb-1 text-xs font-medium text-muted-foreground">SEC filings since the fire</p>
                    {filings.length === 0 ? (
                        <p className="text-xs text-muted-foreground">None — no new dilution paper while this trade is on.</p>
                    ) : (
                        <div className="flex flex-wrap gap-1.5">
                            {filings.map((f) => (
                                <Badge
                                    key={`${f.form}-${f.filed_at}`}
                                    variant="outline"
                                    className="border-amber-500/40 bg-amber-500/10 font-mono text-[10px] text-amber-300"
                                >
                                    {f.form} · {relativeTime(f.filed_at)}
                                </Badge>
                            ))}
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function MentionCurveCard({ curve, firedAt }: { curve: Props['mentionCurve']; firedAt: string }) {
    if (curve.length === 0) {
        return null;
    }

    const firedDay = firedAt.slice(0, 10);
    const max = Math.max(...curve.map((c) => c.mentions), 1);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-1 text-sm">
                    Mention momentum
                    <InfoTip>Daily mentions around the fire. Collapsing buzz while the position is open usually precedes the price rolling over — the crowd left.</InfoTip>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="flex h-24 items-end gap-1">
                    {curve.map((c) => (
                        <div key={c.day} className="group relative flex-1">
                            <div
                                className={cn(
                                    'w-full rounded-t-sm',
                                    c.day === firedDay ? 'bg-amber-500/80' : c.day > firedDay ? 'bg-emerald-500/60' : 'bg-zinc-600/70',
                                )}
                                style={{ height: `${Math.max((c.mentions / max) * 96, 3)}px` }}
                                title={`${c.day}: ${c.mentions} mentions, ${c.authors} authors${c.zscore !== null ? `, z ${c.zscore.toFixed(1)}` : ''}`}
                            />
                        </div>
                    ))}
                </div>
                <p className="mt-2 flex justify-between text-[10px] text-muted-foreground">
                    <span>{curve[0].day}</span>
                    <span className="text-amber-400">▎fired</span>
                    <span>{curve[curve.length - 1].day}</span>
                </p>
            </CardContent>
        </Card>
    );
}

function TapeCard({ posts }: { posts: Post[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">
                    The tape <span className="font-normal text-muted-foreground">— top posts since the fire</span>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex max-h-[32rem] flex-col gap-3 overflow-y-auto">
                {posts.length === 0 && <p className="text-sm text-muted-foreground">No on-topic posts captured since the fire.</p>}
                {posts.map((post) => (
                    <div key={post.id} className="rounded-md border border-border/60 p-2.5 text-xs">
                        <div className="mb-1 flex flex-wrap items-center gap-1.5">
                            <span className="font-mono text-muted-foreground">{post.source.key}</span>
                            {post.author && <span className="text-muted-foreground">@{post.author.username}</span>}
                            <span className="text-muted-foreground">· {relativeTime(post.posted_at)}</span>
                            <span className="text-muted-foreground">· ▲{post.score}</span>
                            {post.sentiment?.llm_post_type && (
                                <Badge variant="outline" className="font-mono text-[9px] text-muted-foreground">
                                    {post.sentiment.llm_post_type}
                                </Badge>
                            )}
                            {post.sentiment?.llm_pump_suspicion !== null &&
                                post.sentiment?.llm_pump_suspicion !== undefined &&
                                post.sentiment.llm_pump_suspicion >= 0.5 && (
                                    <Badge variant="outline" className="border-amber-500/40 font-mono text-[9px] text-amber-400">
                                        pump {(post.sentiment.llm_pump_suspicion * 100).toFixed(0)}%
                                    </Badge>
                                )}
                            {post.permalink && (
                                <a
                                    href={post.permalink}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="ml-auto text-muted-foreground hover:text-foreground"
                                >
                                    <ExternalLink className="size-3" />
                                </a>
                            )}
                        </div>
                        {post.title && <p className="font-medium">{post.title}</p>}
                        {post.body && <p className="mt-0.5 line-clamp-3 text-muted-foreground">{post.body}</p>}
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

SignalCockpit.layout = {
    breadcrumbs: [{ title: 'Signals', href: signals() }],
};
