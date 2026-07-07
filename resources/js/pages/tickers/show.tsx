import { Head, router } from '@inertiajs/react';
import { ExternalLink, Star } from 'lucide-react';
import {
    Bar,
    CartesianGrid,
    ComposedChart,
    Line,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { useEffect, useState } from 'react';
import { MarketStatusBadge, PumpRiskBadge, relativeTime, SentimentBadge } from '@/components/pennyhunt/badges';
import type { MarketStatus } from '@/components/pennyhunt/badges';
import { CandleChart } from '@/components/pennyhunt/candle-chart';
import type { ChartMarker, OhlcBar } from '@/components/pennyhunt/candle-chart';
import { InfoTip } from '@/components/pennyhunt/info-tip';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { radar } from '@/routes';
import { IntervalToggle } from '@/components/pennyhunt/candle-chart';
import type { ChartInterval } from '@/components/pennyhunt/candle-chart';
import { intraday as tickerIntraday } from '@/routes/tickers';
import { destroy as watchlistDestroy, store as watchlistStore } from '@/routes/watchlists';

type SeriesPoint = {
    bucket: string;
    mentions: number;
    unique_authors: number;
    sentiment: number | null;
    zscore: number | null;
};

type TickerPost = {
    id: number;
    kind: string;
    title: string | null;
    body: string | null;
    permalink: string | null;
    score: number;
    posted_at: string;
    source: { key: string; name: string };
    author: { username: string; karma: number | null; pump_risk_score: number } | null;
    sentiment: {
        lexicon_score: number | null;
        llm_direction: string | null;
        llm_post_type: string | null;
        llm_pump_suspicion: number | null;
    } | null;
    voice_rank: number | null;
};

type Tweet = TickerPost & { followers: number | null; retweets: number | null };

type Profile = {
    description: string | null;
    sic_description: string | null;
    homepage_url: string | null;
    primary_exchange: string | null;
    city: string | null;
    state: string | null;
    total_employees: number | null;
    list_date: string | null;
    market_cap: number | null;
    shares_outstanding: number | null;
} | null;

type Financial = {
    end_date: string;
    fiscal: string;
    revenue: number | null;
    net_income: number | null;
    eps_basic: number | null;
    operating_cash_flow: number | null;
    cash: number | null;
    total_assets: number | null;
    total_liabilities: number | null;
    equity: number | null;
};

type Intel = {
    short_ratio: number | null;
    atm_filed_90d: boolean;
    active_shelf: boolean;
    share_growth_12m: number | null;
    market_ret_5d: number | null;
    site_mention_z: number | null;
    vix: number | null;
    btc_ret_5d: number | null;
    mention_streak: number;
    smallcap_rel_20d: number | null;
    xbi_ret_5d: number | null;
    insider_buys_90d: number;
    insider_net_value_90d: number | null;
    news_catalyst_7d: boolean;
    news_offering_7d: boolean;
};

type Technicals = {
    rvol: number | null;
    atr_pct: number | null;
    range_expansion: number | null;
    dist_52w_high: number | null;
    up_streak: number | null;
    gap_open: number | null;
    sector_heat: number | null;
    sector_mention_z: number | null;
};

type InsiderTradeRow = {
    filed_at: string;
    transacted_at: string | null;
    owner_name: string | null;
    is_officer: boolean;
    is_director: boolean;
    code: 'P' | 'S';
    shares: number | null;
    price: number | null;
    value: number | null;
};

type Props = {
    ticker: {
        id: number;
        symbol: string;
        name: string | null;
        exchange: string | null;
        tier: string | null;
        market_cap: number | null;
        last_price: number | null;
        is_ambiguous: boolean;
    };
    profile: Profile;
    financials: Financial[];
    intel: Intel;
    technicals: Technicals;
    insiders: InsiderTradeRow[];
    series: SeriesPoint[];
    bars: OhlcBar[];
    posts: TickerPost[];
    tweets: Tweet[];
    aggregatorHistory: {
        mentions: number | null;
        rank: number | null;
        sentiment_score: number | null;
        sentiment_label: string | null;
        captured_at: string;
    }[];
    signals: {
        id: number;
        composite_score: number;
        state: string;
        fired_at: string;
        forward_return_5d: number | null;
    }[];
    marketStatus: MarketStatus | null;
    isWatched: boolean;
    extendedQuote: {
        price: number;
        change_pct: number | null;
        session: string;
        as_of: string;
        prev_close: number | null;
    } | null;
    news: {
        id: number;
        publisher: string | null;
        title: string;
        article_url: string;
        image_url: string | null;
        published_at: string;
        catalyst_type: string | null;
    }[];
};

function compactMoney(value: number | null): string {
    if (value === null) {
return '—';
}

    const abs = Math.abs(value);
    const sign = value < 0 ? '-' : '';

    if (abs >= 1e9) {
return `${sign}$${(abs / 1e9).toFixed(2)}B`;
}

    if (abs >= 1e6) {
return `${sign}$${(abs / 1e6).toFixed(1)}M`;
}

    if (abs >= 1e3) {
return `${sign}$${(abs / 1e3).toFixed(0)}K`;
}

    return `${sign}$${abs.toFixed(0)}`;
}

function compactCount(value: number | null): string {
    if (value === null) {
return '—';
}

    if (value >= 1e9) {
return `${(value / 1e9).toFixed(2)}B`;
}

    if (value >= 1e6) {
return `${(value / 1e6).toFixed(1)}M`;
}

    if (value >= 1e3) {
return `${(value / 1e3).toFixed(0)}K`;
}

    return `${value}`;
}

const fmtSharePrice = (v: number) => (v >= 1 ? v.toFixed(2) : v.toFixed(4).replace(/0+$/, '').replace(/\.$/, ''));

/** Labelled stat for the header strip. */
function Stat({ label, value, tone }: { label: string; value: string; tone?: 'up' | 'down' }) {
    return (
        <div className="flex flex-col gap-0.5 px-4 first:pl-0">
            <span className="text-[11px] tracking-wide text-muted-foreground uppercase">{label}</span>
            <span
                className={`font-mono text-sm ${tone === 'up' ? 'text-emerald-400' : tone === 'down' ? 'text-rose-400' : ''}`}
            >
                {value}
            </span>
        </div>
    );
}

/** One row of the dilution/short-flow card: label + explainer + value. */
function IntelRow({ label, tip, children }: { label: string; tip: string; children: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-2 px-1 py-1.5">
            <span className="flex items-center gap-1.5 text-muted-foreground">
                {label}
                <InfoTip>{tip}</InfoTip>
            </span>
            {children}
        </div>
    );
}

export default function TickerShow({
    ticker,
    profile,
    financials,
    intel,
    technicals,
    insiders,
    series,
    bars,
    posts,
    tweets,
    aggregatorHistory,
    signals,
    marketStatus,
    isWatched,
    extendedQuote,
    news,
}: Props) {
    const toggleWatch = () => {
        if (isWatched) {
            router.delete(watchlistDestroy(ticker.id).url, { preserveScroll: true, preserveState: true });
        } else {
            router.post(watchlistStore().url, { symbol: ticker.symbol }, { preserveScroll: true, preserveState: true });
        }
    };

    // Signal markers snapped to the last session on/before each fire date.
    const signalMarkers = signals
        .map((signal) => {
            const firedDate = signal.fired_at.slice(0, 10);
            const barDate = [...bars].reverse().find((bar) => bar.date <= firedDate)?.date;

            return barDate ? { date: barDate, label: 'signal', color: '#f59e0b' } : null;
        })
        .filter((marker): marker is NonNullable<typeof marker> => marker !== null)
        .filter((marker, index, all) => all.findIndex((m) => m.date === marker.date) === index);

    const chartData = series.map((point) => ({
        ...point,
        label: new Date(point.bucket).toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
        }),
    }));

    const latestBar = bars.length > 0 ? bars[bars.length - 1] : null;
    const prevBar = bars.length > 1 ? bars[bars.length - 2] : null;
    const lastPrice = latestBar?.close ?? ticker.last_price;
    const dayChange = latestBar && prevBar && prevBar.close > 0 ? latestBar.close / prevBar.close - 1 : null;
    const high12m = bars.length > 0 ? Math.max(...bars.map((bar) => bar.high)) : null;
    const low12m = bars.length > 0 ? Math.min(...bars.map((bar) => bar.low)) : null;
    const marketCap = profile?.market_cap ?? ticker.market_cap;

    return (
        <>
            <Head title={`$${ticker.symbol}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {/* ── Header: identity + live quote ─────────────────────── */}
                <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                    <div className="flex flex-col gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="font-mono text-3xl leading-none font-bold">${ticker.symbol}</h1>
                            <button
                                type="button"
                                onClick={toggleWatch}
                                title={isWatched ? 'Remove from watchlist' : 'Add to watchlist'}
                                className="rounded-md p-1 transition-colors hover:bg-accent"
                            >
                                <Star
                                    className={
                                        isWatched
                                            ? 'size-5 fill-amber-400 text-amber-400'
                                            : 'size-5 text-muted-foreground hover:text-amber-400'
                                    }
                                />
                            </button>
                            {(profile?.primary_exchange ?? ticker.exchange) && (
                                <Badge variant="outline">{profile?.primary_exchange ?? ticker.exchange}</Badge>
                            )}
                            {profile?.sic_description && (
                                <Badge variant="outline" className="max-w-56 truncate text-muted-foreground">
                                    {profile.sic_description}
                                </Badge>
                            )}
                            {ticker.is_ambiguous && (
                                <Badge variant="outline" className="border-amber-500/40 text-amber-400">
                                    ambiguous symbol — only $-tagged mentions counted
                                </Badge>
                            )}
                        </div>
                        <span className="text-sm text-muted-foreground">{ticker.name}</span>
                    </div>
                    <div className="flex flex-col items-end gap-1.5">
                        {lastPrice !== null && (
                            <div className="flex items-baseline gap-3">
                                <span className="font-mono text-3xl leading-none font-semibold">
                                    ${fmtSharePrice(lastPrice)}
                                </span>
                                {dayChange !== null && (
                                    <span
                                        className={`font-mono text-lg ${dayChange >= 0 ? 'text-emerald-400' : 'text-rose-400'}`}
                                    >
                                        {dayChange >= 0 ? '+' : ''}
                                        {(dayChange * 100).toFixed(2)}%
                                    </span>
                                )}
                            </div>
                        )}
                        {extendedQuote && extendedQuote.price !== lastPrice && (
                            <div className="flex items-baseline gap-2 font-mono text-sm">
                                <span className="text-[11px] tracking-wide text-muted-foreground uppercase">
                                    {extendedQuote.session === 'early_hours'
                                        ? 'pre-market'
                                        : extendedQuote.session === 'after_hours'
                                          ? 'after hours'
                                          : 'live'}
                                </span>
                                <span className="text-foreground">${fmtSharePrice(extendedQuote.price)}</span>
                                {extendedQuote.change_pct !== null && (
                                    <span className={extendedQuote.change_pct >= 0 ? 'text-emerald-400' : 'text-rose-400'}>
                                        {extendedQuote.change_pct >= 0 ? '+' : ''}
                                        {(extendedQuote.change_pct * 100).toFixed(2)}%
                                    </span>
                                )}
                            </div>
                        )}
                        <MarketStatusBadge market={marketStatus} />
                    </div>
                </div>

                {/* ── Key stats strip ────────────────────────────────────── */}
                <div className="flex flex-wrap divide-x divide-border/60 rounded-lg border border-border/60 bg-card px-4 py-2.5">
                    <Stat label="Market cap" value={compactMoney(marketCap)} />
                    <Stat label="Shares out" value={compactCount(profile?.shares_outstanding ?? null)} />
                    <Stat label="12M high" value={high12m !== null ? `$${fmtSharePrice(high12m)}` : '—'} />
                    <Stat label="12M low" value={low12m !== null ? `$${fmtSharePrice(low12m)}` : '—'} />
                    <Stat label="Volume" value={latestBar ? compactCount(latestBar.volume) : '—'} />
                    <Stat
                        label="$ volume"
                        value={latestBar ? compactMoney(latestBar.volume * latestBar.close) : '—'}
                    />
                    <Stat label="Employees" value={compactCount(profile?.total_employees ?? null)} />
                    <Stat label="Listed" value={profile?.list_date?.slice(0, 10) ?? '—'} />
                </div>

                {/* ── Price chart ────────────────────────────────────────── */}
                <TickerChartCard symbol={ticker.symbol} bars={bars} dailyMarkers={signalMarkers} />

                {/* ── Company + model features ───────────────────────────── */}
                <div className="grid gap-4 xl:grid-cols-3">
                    <Card className="xl:col-span-2">
                        <CardHeader>
                            <CardTitle className="text-sm">
                                Company{' '}
                                <span className="font-normal text-muted-foreground">(Polygon reference data)</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {!profile ? (
                                <p className="py-4 text-center text-sm text-muted-foreground">
                                    Profile syncing — refresh in a moment.
                                </p>
                            ) : (
                                <>
                                    {profile.description && (
                                        <p className="text-sm leading-relaxed text-muted-foreground">
                                            {profile.description}
                                        </p>
                                    )}
                                    <div className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm md:grid-cols-3">
                                        <div className="flex justify-between gap-2">
                                            <span className="text-muted-foreground">Industry</span>
                                            <span className="truncate text-right">{profile.sic_description ?? '—'}</span>
                                        </div>
                                        <div className="flex justify-between gap-2">
                                            <span className="text-muted-foreground">HQ</span>
                                            <span>{[profile.city, profile.state].filter(Boolean).join(', ') || '—'}</span>
                                        </div>
                                        <div className="flex justify-between gap-2">
                                            <span className="text-muted-foreground">Listed</span>
                                            <span className="font-mono">{profile.list_date?.slice(0, 10) ?? '—'}</span>
                                        </div>
                                    </div>
                                    {profile.homepage_url && (
                                        <a
                                            href={profile.homepage_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                        >
                                            <ExternalLink className="size-3" /> {profile.homepage_url}
                                        </a>
                                    )}
                                </>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-1.5 text-sm">
                                Dilution & short flow
                                <InfoTip>
                                    Point-in-time risk features, computed exactly as the signal model sees them (no
                                    look-ahead). These separate organic runs from pumps that get sold into.
                                </InfoTip>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col text-sm">
                            <IntelRow
                                label="Short volume ratio"
                                tip="Share of the day's trading volume that was sold short (FINRA Reg SHO daily file, latest session, up to 6 days stale). 40–50% is normal market-making; sustained 60%+ means heavy shorting pressure — potential squeeze fuel, but also informed money betting against the stock."
                            >
                                <span className="font-mono">
                                    {intel.short_ratio !== null ? `${(intel.short_ratio * 100).toFixed(0)}%` : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="Active shelf"
                                tip="An S-3/F-3 shelf registration was filed within the last 3 years — the company is pre-approved to issue new shares at any moment. Rallies into an active shelf are often sold into by the company itself (dilution capacity sitting on the shelf)."
                            >
                                {intel.active_shelf ? (
                                    <Badge variant="outline" className="border-amber-500/40 text-amber-400">
                                        yes — dilution capacity
                                    </Badge>
                                ) : (
                                    <span className="font-mono text-muted-foreground">no</span>
                                )}
                            </IntelRow>
                            <IntelRow
                                label="Prospectus takedown"
                                tip="A 424B prospectus was filed within the last 90 days — the company recently sold (or is actively selling) new shares, e.g. an at-the-market offering. The strongest dilution red flag: fresh supply hits every rally. Counterintuitively, it also flags the most pumpable floats — our model weights it positive for P(hit) but it caps upside."
                            >
                                {intel.atm_filed_90d ? (
                                    <Badge variant="outline" className="border-red-500/40 text-red-400">
                                        yes — selling shares
                                    </Badge>
                                ) : (
                                    <span className="font-mono text-muted-foreground">no</span>
                                )}
                            </IntelRow>
                            <IntelRow
                                label="Share growth 12m"
                                tip="Change in shares outstanding vs 12 months ago, from SEC XBRL filing cover pages. Above +20% existing holders were heavily diluted — price rallies get absorbed by fresh supply."
                            >
                                <span className="font-mono">
                                    {intel.share_growth_12m !== null
                                        ? `${intel.share_growth_12m > 0 ? '+' : ''}${(intel.share_growth_12m * 100).toFixed(1)}%`
                                        : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="Small-cap regime"
                                tip="Russell 2000 ETF (IWM) return over the last 5 sessions — the tide small caps swim in. Used as regime context by the model; in our backtest the tape direction changed which signals worked."
                            >
                                <span className="font-mono">
                                    {intel.market_ret_5d !== null
                                        ? `${intel.market_ret_5d > 0 ? '+' : ''}${(intel.market_ret_5d * 100).toFixed(1)}%`
                                        : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="VIX"
                                tip="CBOE volatility index (the market's fear gauge), latest close. In our 12-month backtest, entries taken at VIX ≥ 25 lost the most (−11.5%/trade net): when the whole market is stressed, losers gap through stops."
                            >
                                <span
                                    className={`font-mono ${intel.vix !== null && intel.vix >= 25 ? 'text-rose-400' : intel.vix !== null && intel.vix >= 20 ? 'text-amber-400' : ''}`}
                                >
                                    {intel.vix !== null ? intel.vix.toFixed(1) : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="BTC 5d"
                                tip="Bitcoin's return over the last 5 days — the cleanest daily proxy for retail speculative risk appetite, which penny-stock flows chase. Extreme crypto euphoria historically coincided with late, crowded entries."
                            >
                                <span className="font-mono">
                                    {intel.btc_ret_5d !== null
                                        ? `${intel.btc_ret_5d > 0 ? '+' : ''}${(intel.btc_ret_5d * 100).toFixed(1)}%`
                                        : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="Site-wide buzz"
                                tip="Today's total mentions across ALL tickers vs the trailing 30-day average (z-score). Above ~1.5 the whole casino is running hot — in our backtest crowded attention roughly halved the hit rate."
                            >
                                <span
                                    className={`font-mono ${intel.site_mention_z !== null && intel.site_mention_z >= 1.5 ? 'text-amber-400' : ''}`}
                                >
                                    {intel.site_mention_z !== null ? `${intel.site_mention_z.toFixed(1)}σ` : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="Mention streak"
                                tip="Consecutive days this ticker's mentions rose vs the day before. 0 = today's buzz is a one-shot spike; 3+ = attention has been building for days (momentum continuation — what 100%/5d moves are made of)."
                            >
                                <span className={`font-mono ${intel.mention_streak >= 3 ? 'text-emerald-400' : ''}`}>
                                    {intel.mention_streak}d{intel.mention_streak >= 3 ? ' building' : ''}
                                </span>
                            </IntelRow>
                        </CardContent>
                    </Card>
                </div>

                {/* ── Tape, sector & insider flow (model features, phase D) ── */}
                <div className="grid gap-4 xl:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-1.5 text-sm">
                                Tape & technicals
                                <InfoTip>
                                    Computed from this ticker's own daily bars, exactly as the signal model sees them.
                                    The tape either confirms the buzz or exposes it.
                                </InfoTip>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col text-sm">
                            <IntelRow
                                label="Relative volume"
                                tip="Latest session volume vs the trailing 20-session average. RVOL 1 = business as usual; 5+ = something is happening — the single metric momentum traders check first."
                            >
                                <span
                                    className={`font-mono ${(technicals.rvol ?? 0) >= 3 ? 'text-emerald-400' : ''}`}
                                >
                                    {technicals.rvol !== null ? `${technicals.rvol.toFixed(1)}x` : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="ATR (14d)"
                                tip="Average true range as a % of price — this stock's normal daily swing. Penny movers often run 8-15%; it also sizes how far a stop needs to sit."
                            >
                                <span className="font-mono">
                                    {technicals.atr_pct !== null ? `${(technicals.atr_pct * 100).toFixed(1)}%` : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="Range expansion"
                                tip="Latest session's true range vs its own 14-day ATR. Above ~2 the bar is unusually wide — breakout (or breakdown) behavior, not noise."
                            >
                                <span
                                    className={`font-mono ${(technicals.range_expansion ?? 0) >= 2 ? 'text-amber-400' : ''}`}
                                >
                                    {technicals.range_expansion !== null
                                        ? `${technicals.range_expansion.toFixed(1)}x`
                                        : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="From 52w high"
                                tip="Close vs the trailing-year high. Near 0% = breaking out to new highs (no bagholders overhead); −80% = a broken chart where every rally meets trapped sellers."
                            >
                                <span className="font-mono">
                                    {technicals.dist_52w_high !== null
                                        ? `${(technicals.dist_52w_high * 100).toFixed(0)}%`
                                        : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="Up-day streak"
                                tip="Consecutive up-closes ending at the latest session. Multi-day persistence separates campaigns from one-day wonders."
                            >
                                <span
                                    className={`font-mono ${(technicals.up_streak ?? 0) >= 3 ? 'text-emerald-400' : ''}`}
                                >
                                    {technicals.up_streak !== null ? `${technicals.up_streak}d` : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="Gap open"
                                tip="Latest open vs the prior close — overnight repricing (news, PR) that intraday flow then confirms or fades."
                            >
                                <span className="font-mono">
                                    {technicals.gap_open !== null
                                        ? `${technicals.gap_open > 0 ? '+' : ''}${(technicals.gap_open * 100).toFixed(1)}%`
                                        : '—'}
                                </span>
                            </IntelRow>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-1.5 text-sm">
                                Sector & macro regime
                                <InfoTip>
                                    Penny explosions cluster: when one name in a sector rips, peers follow within days
                                    (sympathy plays). The macro rows say whether small-cap buzz can convert at all.
                                </InfoTip>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col text-sm">
                            <IntelRow
                                label="Sector heat"
                                tip="Share of same-industry peers (SIC group, socially-tracked universe) that gained 20%+ over the last 5 sessions — excluding this ticker. High heat = a sympathy rotation is underway."
                            >
                                <span
                                    className={`font-mono ${(technicals.sector_heat ?? 0) >= 0.15 ? 'text-emerald-400' : ''}`}
                                >
                                    {technicals.sector_heat !== null
                                        ? `${(technicals.sector_heat * 100).toFixed(0)}% peers hot`
                                        : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="Sector buzz"
                                tip="Today's sector-wide mention count vs its trailing 30-day baseline (z-score). Social contagion usually shows up here before price does."
                            >
                                <span
                                    className={`font-mono ${(technicals.sector_mention_z ?? 0) >= 1.5 ? 'text-amber-400' : ''}`}
                                >
                                    {technicals.sector_mention_z !== null
                                        ? `${technicals.sector_mention_z.toFixed(1)}σ`
                                        : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="Small-cap appetite"
                                tip="IWM minus SPY, 20-session returns. Positive = small caps leading the market (buzz converts into moves); negative = flight to quality (pumps die on the vine)."
                            >
                                <span
                                    className={`font-mono ${(intel.smallcap_rel_20d ?? 0) > 0 ? 'text-emerald-400' : 'text-rose-400'}`}
                                >
                                    {intel.smallcap_rel_20d !== null
                                        ? `${intel.smallcap_rel_20d > 0 ? '+' : ''}${(intel.smallcap_rel_20d * 100).toFixed(1)}%`
                                        : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="Biotech tape (XBI 5d)"
                                tip="Biotech ETF return over 5 sessions — the speculative end of small caps. A rising XBI is the tide that floats low-float biotech and pharma runners."
                            >
                                <span className="font-mono">
                                    {intel.xbi_ret_5d !== null
                                        ? `${intel.xbi_ret_5d > 0 ? '+' : ''}${(intel.xbi_ret_5d * 100).toFixed(1)}%`
                                        : '—'}
                                </span>
                            </IntelRow>
                            <IntelRow
                                label="News catalyst (7d)"
                                tip="An LLM-classified positive catalyst headline (FDA, contract, merger, uplisting…) published within 7 days. Buzz + a real catalyst is a different trade than buzz alone."
                            >
                                {intel.news_catalyst_7d ? (
                                    <Badge variant="outline" className="border-emerald-500/40 text-emerald-400">
                                        yes
                                    </Badge>
                                ) : (
                                    <span className="font-mono text-muted-foreground">no</span>
                                )}
                            </IntelRow>
                            <IntelRow
                                label="Offering news (7d)"
                                tip="An offering / dilution / reverse-split headline within 7 days — fresh supply meeting the rally."
                            >
                                {intel.news_offering_7d ? (
                                    <Badge variant="outline" className="border-red-500/40 text-red-400">
                                        yes — dilution
                                    </Badge>
                                ) : (
                                    <span className="font-mono text-muted-foreground">no</span>
                                )}
                            </IntelRow>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-1.5 text-sm">
                                Insider activity
                                <InfoTip>
                                    Open-market Form 4 purchases and sales (SEC EDGAR). Insiders buying their own
                                    sub-$5 stock with their own money is one of the strongest known signals; grants
                                    and option exercises are excluded.
                                </InfoTip>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-1.5 text-sm">
                            {insiders.length === 0 ? (
                                <p className="py-4 text-center text-sm text-muted-foreground">
                                    No open-market insider transactions on record.
                                </p>
                            ) : (
                                insiders.map((trade, i) => (
                                    <div
                                        key={i}
                                        className="flex items-center justify-between gap-2 rounded-md border border-border/60 px-2.5 py-1.5"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-1.5">
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        trade.code === 'P'
                                                            ? 'border-emerald-500/40 text-emerald-400'
                                                            : 'border-red-500/40 text-red-400'
                                                    }
                                                >
                                                    {trade.code === 'P' ? 'BUY' : 'SELL'}
                                                </Badge>
                                                <span className="truncate text-xs">
                                                    {trade.owner_name ?? 'Insider'}
                                                    {trade.is_officer && ' · officer'}
                                                    {trade.is_director && ' · director'}
                                                </span>
                                            </div>
                                            <span className="text-[11px] text-muted-foreground">
                                                filed {trade.filed_at}
                                            </span>
                                        </div>
                                        <span className="font-mono text-xs whitespace-nowrap">
                                            {trade.value !== null ? compactMoney(trade.value) : '—'}
                                        </span>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ── Financials ─────────────────────────────────────────── */}
                {financials.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-1.5 text-sm">
                                Financials — quarterly
                                <InfoTip>
                                    Standardized statements from SEC XBRL filings (via Polygon). For penny stocks the
                                    key reads are: is revenue real, how fast is cash burning (net income / operating
                                    cash flow), and is equity still positive — negative equity plus an active shelf
                                    usually means dilution is how the lights stay on.
                                </InfoTip>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-border/60 text-left text-xs text-muted-foreground">
                                        <th className="py-1.5 pr-4 font-normal">Period</th>
                                        <th className="py-1.5 pr-4 text-right font-normal">Revenue</th>
                                        <th className="py-1.5 pr-4 text-right font-normal">Net income</th>
                                        <th className="py-1.5 pr-4 text-right font-normal">EPS</th>
                                        <th className="py-1.5 pr-4 text-right font-normal">Op. cash flow</th>
                                        <th className="py-1.5 pr-4 text-right font-normal">Assets</th>
                                        <th className="py-1.5 pr-4 text-right font-normal">Liabilities</th>
                                        <th className="py-1.5 text-right font-normal">Equity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {financials.map((f) => (
                                        <tr key={f.end_date} className="border-b border-border/40">
                                            <td className="py-1.5 pr-4 font-mono text-xs">
                                                {f.fiscal || f.end_date}
                                                <span className="ml-2 text-muted-foreground">{f.end_date}</span>
                                            </td>
                                            <td className="py-1.5 pr-4 text-right font-mono">{compactMoney(f.revenue)}</td>
                                            <td
                                                className={`py-1.5 pr-4 text-right font-mono ${
                                                    f.net_income !== null && f.net_income < 0 ? 'text-rose-400' : ''
                                                }`}
                                            >
                                                {compactMoney(f.net_income)}
                                            </td>
                                            <td className="py-1.5 pr-4 text-right font-mono">
                                                {f.eps_basic !== null ? f.eps_basic.toFixed(2) : '—'}
                                            </td>
                                            <td
                                                className={`py-1.5 pr-4 text-right font-mono ${
                                                    f.operating_cash_flow !== null && f.operating_cash_flow < 0
                                                        ? 'text-rose-400'
                                                        : ''
                                                }`}
                                            >
                                                {compactMoney(f.operating_cash_flow)}
                                            </td>
                                            <td className="py-1.5 pr-4 text-right font-mono">{compactMoney(f.total_assets)}</td>
                                            <td className="py-1.5 pr-4 text-right font-mono">{compactMoney(f.total_liabilities)}</td>
                                            <td
                                                className={`py-1.5 text-right font-mono ${
                                                    f.equity !== null && f.equity < 0 ? 'text-rose-400' : ''
                                                }`}
                                            >
                                                {compactMoney(f.equity)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                )}

                {/* ── Verified Twitter voices ───────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-1.5 text-sm">
                            X / Twitter — verified voices
                            <InfoTip>
                                Tweets mentioning this ticker from verified profiles only, ranked by real like counts
                                (last 30 days). Verified + high engagement filters out the bot swarm that dominates
                                cashtag feeds.
                            </InfoTip>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2">
                        {tweets.length === 0 ? (
                            <p className="py-4 text-center text-sm text-muted-foreground">
                                No verified tweets captured for this ticker yet — the hourly cashtag poller only
                                queries tickers currently trending on Reddit.
                            </p>
                        ) : (
                            tweets.map((tweet) => (
                                <div key={tweet.id} className="rounded-md border border-border/60 px-3 py-2">
                                    <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        <Badge variant="outline" className="border-sky-500/40 text-sky-400">
                                            verified
                                        </Badge>
                                        <span className="font-medium text-foreground">@{tweet.author?.username}</span>
                                        {tweet.followers !== null && <span>{compactCount(tweet.followers)} followers</span>}
                                        <span>·</span>
                                        <span>{relativeTime(tweet.posted_at)}</span>
                                        <span className="ml-auto flex items-center gap-3 font-mono">
                                            <span>♥ {compactCount(tweet.score)}</span>
                                            {tweet.retweets !== null && <span>↻ {compactCount(tweet.retweets)}</span>}
                                        </span>
                                    </div>
                                    {tweet.body && <p className="mt-1 text-sm">{tweet.body}</p>}
                                    {tweet.permalink && (
                                        <a
                                            href={tweet.permalink}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="mt-1 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                        >
                                            <ExternalLink className="size-3" /> view on X
                                        </a>
                                    )}
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>

                {/* ── Mentions & sentiment ──────────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">
                            Mentions & sentiment — last 7 days{' '}
                            <span className="font-normal text-muted-foreground">(hourly buckets, own ingestion)</span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {chartData.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                No mention data for this ticker yet.
                            </p>
                        ) : (
                            <ResponsiveContainer width="100%" height={280}>
                                <ComposedChart data={chartData}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.06)" />
                                    <XAxis dataKey="label" tick={{ fontSize: 11, fill: '#71717a' }} />
                                    <YAxis yAxisId="mentions" tick={{ fontSize: 11, fill: '#71717a' }} />
                                    <YAxis
                                        yAxisId="sentiment"
                                        orientation="right"
                                        domain={[-1, 1]}
                                        tick={{ fontSize: 11, fill: '#71717a' }}
                                    />
                                    <Tooltip
                                        contentStyle={{
                                            backgroundColor: '#18181b',
                                            border: '1px solid #27272a',
                                            borderRadius: 8,
                                            fontSize: 12,
                                        }}
                                    />
                                    <Bar yAxisId="mentions" dataKey="mentions" fill="#10b981" opacity={0.7} name="Mentions" />
                                    <Line
                                        yAxisId="sentiment"
                                        type="monotone"
                                        dataKey="sentiment"
                                        stroke="#f59e0b"
                                        dot={false}
                                        name="Weighted sentiment"
                                        connectNulls
                                    />
                                </ComposedChart>
                            </ResponsiveContainer>
                        )}
                    </CardContent>
                </Card>

                {/* ── Posts + signal history + aggregators ─────────────── */}
                <div className="grid gap-4 xl:grid-cols-3">
                    <Card className="xl:col-span-2">
                        <CardHeader>
                            <CardTitle className="text-sm">Posts driving the buzz</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2">
                            {posts.length === 0 ? (
                                <p className="py-6 text-center text-sm text-muted-foreground">No posts yet.</p>
                            ) : (
                                posts.map((post) => (
                                    <div key={post.id} className="rounded-md border border-border/60 px-3 py-2">
                                        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            <Badge variant="outline" className="text-xs">
                                                {post.source.name}
                                            </Badge>
                                            <span>{post.author?.username ?? '[deleted]'}</span>
                                            {post.voice_rank !== null && (
                                                <Badge
                                                    className="bg-amber-500/15 text-[10px] font-semibold text-amber-600 dark:text-amber-400"
                                                    title={`Ranked #${post.voice_rank} on the Voices leaderboard — proven track record of early calls on winners`}
                                                >
                                                    voice #{post.voice_rank}
                                                </Badge>
                                            )}
                                            <span>·</span>
                                            <span>{relativeTime(post.posted_at)}</span>
                                            <span className="ml-auto flex gap-2">
                                                {post.sentiment?.llm_post_type && (
                                                    <Badge variant="outline" className="text-xs text-muted-foreground">
                                                        {post.sentiment.llm_post_type}
                                                    </Badge>
                                                )}
                                                <SentimentBadge value={post.sentiment?.lexicon_score} />
                                                <PumpRiskBadge
                                                    value={post.sentiment?.llm_pump_suspicion ?? post.author?.pump_risk_score}
                                                />
                                            </span>
                                        </div>
                                        {post.title && <p className="mt-1 text-sm font-medium">{post.title}</p>}
                                        {post.body && (
                                            <p className="mt-0.5 line-clamp-2 text-sm text-muted-foreground">{post.body}</p>
                                        )}
                                        {post.permalink && (
                                            <a
                                                href={post.permalink}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="mt-1 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                            >
                                                <ExternalLink className="size-3" /> view on source
                                            </a>
                                        )}
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-1.5 text-sm">
                                    Latest news
                                    <InfoTip>
                                        Wire coverage from Polygon, refreshed in the background when you open this page
                                        (at most every 6 hours).
                                    </InfoTip>
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2">
                                {news.length === 0 ? (
                                    <p className="py-4 text-center text-sm text-muted-foreground">
                                        No recent coverage found — syncing in the background.
                                    </p>
                                ) : (
                                    news.map((item) => (
                                        <a
                                            key={item.id}
                                            href={item.article_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="group rounded-md border border-border/50 px-3 py-2 transition-colors hover:bg-accent"
                                        >
                                            <p className="text-sm leading-snug font-medium group-hover:underline">{item.title}</p>
                                            <span className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                                                {item.catalyst_type && item.catalyst_type !== 'none' && (
                                                    <Badge
                                                        variant="outline"
                                                        className={
                                                            item.catalyst_type === 'offering' ||
                                                            item.catalyst_type === 'short_report' ||
                                                            item.catalyst_type === 'legal'
                                                                ? 'border-red-500/40 text-[10px] text-red-400'
                                                                : 'border-emerald-500/40 text-[10px] text-emerald-400'
                                                        }
                                                    >
                                                        {item.catalyst_type.replace('_', ' ')}
                                                    </Badge>
                                                )}
                                                <span>{item.publisher ?? 'wire'}</span>
                                                <span>{relativeTime(item.published_at)}</span>
                                                <ExternalLink className="ml-auto size-3" />
                                            </span>
                                        </a>
                                    ))
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm">Signal history</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2">
                                {signals.length === 0 ? (
                                    <p className="py-4 text-center text-sm text-muted-foreground">
                                        No signals fired for this ticker.
                                    </p>
                                ) : (
                                    signals.map((signal) => (
                                        <div
                                            key={signal.id}
                                            className="flex items-center justify-between rounded-md border border-border/60 px-3 py-2 text-sm"
                                        >
                                            <span className="text-xs text-muted-foreground">
                                                {relativeTime(signal.fired_at)}
                                            </span>
                                            <span className="font-mono text-emerald-400">
                                                {(signal.composite_score * 100).toFixed(0)}
                                            </span>
                                            <span className="font-mono text-xs">
                                                {signal.forward_return_5d !== null
                                                    ? `${signal.forward_return_5d > 0 ? '+' : ''}${(signal.forward_return_5d * 100).toFixed(1)}% 5d`
                                                    : 'ungraded'}
                                            </span>
                                        </div>
                                    ))
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm">
                                    Aggregator view <span className="font-normal text-muted-foreground">— cross-validation</span>
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-1">
                                {aggregatorHistory.length === 0 ? (
                                    <p className="py-4 text-center text-sm text-muted-foreground">
                                        Not currently tracked by aggregators.
                                    </p>
                                ) : (
                                    aggregatorHistory.slice(-10).map((snap, index) => (
                                        <div
                                            key={index}
                                            className="flex items-center justify-between px-2 py-1 text-xs text-muted-foreground"
                                        >
                                            <span>{relativeTime(snap.captured_at)}</span>
                                            <span className="font-mono">#{snap.rank ?? '—'}</span>
                                            <span className="font-mono">{snap.mentions ?? '—'} mentions</span>
                                            <SentimentBadge value={snap.sentiment_score} />
                                        </div>
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}

TickerShow.layout = {
    breadcrumbs: [{ title: 'Radar', href: radar() }],
};

type IntradayPayload = {
    bars: OhlcBar[];
    markers: { time: number; label: string; color: string }[];
};

/**
 * Price chart with a 1D / 1H / 5m interval switcher. Daily bars come with
 * the page; intraday bars are fetched on demand from Polygon (1H covers a
 * quarter, 5m two trading weeks) with signal fires marked at their exact
 * time.
 */
function TickerChartCard({ symbol, bars, dailyMarkers }: { symbol: string; bars: OhlcBar[]; dailyMarkers: ChartMarker[] }) {
    const [interval, setInterval] = useState<ChartInterval>('1d');
    const [intraday, setIntraday] = useState<Partial<Record<'1h' | '5m', IntradayPayload>>>({});
    const [error, setError] = useState(false);

    useEffect(() => {
        if (interval === '1d' || intraday[interval] !== undefined) {
            return;
        }

        let cancelled = false;
        const key = interval;

        fetch(tickerIntraday(symbol, { query: { interval: key } }).url, { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : Promise.reject(new Error(String(res.status)))))
            .then((json: IntradayPayload) => {
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
    }, [interval, symbol, intraday]);

    const payload = interval !== '1d' ? intraday[interval] : undefined;

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="text-sm">
                        Price & volume{' '}
                        <span className="font-normal text-muted-foreground">
                            {interval === '1d'
                                ? '(daily OHLC, orange arrows mark fired signals)'
                                : interval === '1h'
                                  ? '(hourly incl. pre/after-market, ~3 months — fires marked at their exact hour)'
                                  : '(5-minute tape incl. pre/after-market, ~2 weeks)'}
                        </span>
                    </CardTitle>
                    <IntervalToggle value={interval} onChange={setInterval} />
                </div>
            </CardHeader>
            <CardContent>
                {interval === '1d' ? (
                    <CandleChart key="1d" bars={bars} markers={dailyMarkers} height={380} />
                ) : error ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">Could not load intraday data.</p>
                ) : payload === undefined ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">Loading intraday bars…</p>
                ) : (
                    <CandleChart key={interval} bars={payload.bars} markers={payload.markers} height={380} intraday />
                )}
            </CardContent>
        </Card>
    );
}
