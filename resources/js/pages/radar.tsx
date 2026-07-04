import { Head, Link, router } from '@inertiajs/react';
import { useEchoPublic } from '@laravel/echo-react';
import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import { FreshnessChip, relativeTime, SentimentBadge, TierBadge, TradeStatusBadge, ZScoreBadge } from '@/components/pennyhunt/badges';
import { InfoTip } from '@/components/pennyhunt/info-tip';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { radar } from '@/routes';
import { show as signalShow } from '@/routes/signals';
import { show as tickerShow } from '@/routes/tickers';

type LeaderboardRow = {
    ticker_id: number;
    symbol: string;
    name: string | null;
    exchange: string | null;
    last_price: number | null;
    mention_count: number;
    unique_authors: number;
    weighted_sentiment: number | null;
    zscore_mentions: number | null;
    author_quality_avg: number | null;
    bucket_start: string;
    composite: number;
    forming: boolean;
};

type AggregatorMover = {
    symbol: string;
    mentions: number;
    mentions_24h_ago: number;
    change_pct: number;
    rank: number | null;
    rank_24h_ago: number | null;
    sentiment_label: string | null;
    sentiment_score: number | null;
};

type RecentSignal = {
    id: number;
    symbol: string;
    score: number;
    confidence: number | null;
    state: string;
    fired_at: string;
    trade_status: string | null;
};

type Position = {
    id: number;
    signal_id: number;
    symbol: string;
    status: string;
    entry_price: number | null;
    stop_price: number | null;
    last_quote: number | null;
    unrealized_return: number | null;
    holding_day: number | null;
};

type Props = {
    leaderboard: LeaderboardRow[];
    aggregatorMovers: AggregatorMover[];
    recentSignals: RecentSignal[];
    positions: Position[];
    regime: {
        vix: number | null;
        market_ret_5d: number | null;
        btc_ret_5d: number | null;
        site_mention_z: number | null;
    };
    tradeTier: { raw_p: number; calibrated_p: number } | null;
    fireThreshold: number;
    freshness: { aggregator_at: string | null; metrics_at: string | null };
};

const pct = (v: number | null | undefined, digits = 1) =>
    v === null || v === undefined ? '—' : `${v >= 0 ? '+' : ''}${(v * 100).toFixed(digits)}%`;

const returnColor = (v: number | null | undefined) =>
    v === null || v === undefined ? 'text-muted-foreground' : v > 0 ? 'text-emerald-400' : v < 0 ? 'text-rose-400' : 'text-muted-foreground';

export default function Radar({
    leaderboard,
    aggregatorMovers,
    recentSignals,
    positions,
    regime,
    tradeTier,
    fireThreshold,
    freshness,
}: Props) {
    useEchoPublic('pennyhunt.signals', '.signal.fired', () => {
        router.reload({ only: ['recentSignals', 'leaderboard'] });
    });

    useEchoPublic('pennyhunt.trades', '.trade.updated', () => {
        router.reload({ only: ['positions'] });
    });

    return (
        <>
            <Head title="Radar" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-lg font-semibold">Radar</h1>
                    <div className="flex gap-2">
                        <FreshnessChip label="Own metrics" at={freshness.metrics_at} />
                        <FreshnessChip label="Aggregators" at={freshness.aggregator_at} />
                    </div>
                </div>

                <RegimeBanner regime={regime} />

                <div className="grid gap-4 xl:grid-cols-3">
                    <Card className="xl:col-span-2">
                        <CardHeader>
                            <CardTitle className="text-sm">
                                Attention leaderboard{' '}
                                <span className="font-normal text-muted-foreground">
                                    — hourly mentions vs each ticker's own 30-day baseline; amber rows are forming setups
                                    nearing the {Math.round(fireThreshold * 100)} fire threshold
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {leaderboard.length === 0 ? (
                                <EmptyState message="No mention metrics yet. Once Reddit ingestion runs (Sources page shows per-source status), tickers appear here ranked by mention acceleration." />
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Ticker</TableHead>
                                            <TableHead className="text-right">Composite</TableHead>
                                            <TableHead className="text-right">Mentions / h</TableHead>
                                            <TableHead className="text-right">Authors</TableHead>
                                            <TableHead>Accel</TableHead>
                                            <TableHead>Sentiment</TableHead>
                                            <TableHead className="text-right">Quality</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {leaderboard.map((row) => (
                                            <TableRow key={row.ticker_id} className={cn(row.forming && 'bg-amber-500/5')}>
                                                <TableCell>
                                                    <Link
                                                        href={tickerShow(row.symbol)}
                                                        className="font-mono font-semibold text-foreground hover:underline"
                                                    >
                                                        {row.symbol}
                                                    </Link>
                                                    <span className="ml-2 max-w-40 truncate text-xs text-muted-foreground">
                                                        {row.name}
                                                    </span>
                                                    {row.forming && (
                                                        <Badge
                                                            variant="outline"
                                                            className="ml-2 border-amber-500/40 bg-amber-500/10 font-mono text-[9px] text-amber-400"
                                                        >
                                                            forming
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell
                                                    className={cn(
                                                        'text-right font-mono',
                                                        row.composite >= fireThreshold
                                                            ? 'font-semibold text-emerald-400'
                                                            : row.forming
                                                              ? 'text-amber-400'
                                                              : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {(row.composite * 100).toFixed(0)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono">{row.mention_count}</TableCell>
                                                <TableCell className="text-right font-mono">{row.unique_authors}</TableCell>
                                                <TableCell>
                                                    <ZScoreBadge value={row.zscore_mentions} />
                                                </TableCell>
                                                <TableCell>
                                                    <SentimentBadge value={row.weighted_sentiment} />
                                                </TableCell>
                                                <TableCell className="text-right font-mono text-xs text-muted-foreground">
                                                    {row.author_quality_avg !== null
                                                        ? row.author_quality_avg.toFixed(2)
                                                        : '—'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex flex-col gap-4">
                        <PositionsRail positions={positions} />

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm">
                                    Recent signals{' '}
                                    <span className="font-normal text-muted-foreground">— click for the cockpit</span>
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2">
                                {recentSignals.length === 0 ? (
                                    <EmptyState message="No signals fired yet." />
                                ) : (
                                    recentSignals.map((signal) => (
                                        <Link
                                            key={signal.id}
                                            href={signalShow(signal.id)}
                                            className="flex items-center justify-between gap-2 rounded-md border border-border/60 px-3 py-2 hover:bg-muted/50"
                                        >
                                            <span className="flex items-center gap-2">
                                                <span className="font-mono font-semibold">{signal.symbol}</span>
                                                <TierBadge
                                                    confidence={signal.confidence}
                                                    threshold={tradeTier?.calibrated_p ?? null}
                                                />
                                                {signal.trade_status && <TradeStatusBadge status={signal.trade_status} />}
                                            </span>
                                            <span className="flex items-center gap-2">
                                                <span className="text-[10px] text-muted-foreground">
                                                    {relativeTime(signal.fired_at)}
                                                </span>
                                                <span className="font-mono text-sm text-emerald-400">
                                                    {(signal.score * 100).toFixed(0)}
                                                </span>
                                            </span>
                                        </Link>
                                    ))
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm">
                                    Cross-source movers{' '}
                                    <span className="font-normal text-muted-foreground">— ApeWisdom / Tradestie, 24h</span>
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-1">
                                {aggregatorMovers.length === 0 ? (
                                    <EmptyState message="Waiting for the first aggregator poll (runs every 30 min)." />
                                ) : (
                                    aggregatorMovers.slice(0, 15).map((mover) => (
                                        <Link
                                            key={mover.symbol}
                                            href={tickerShow(mover.symbol)}
                                            className="flex items-center justify-between rounded-md px-2 py-1.5 hover:bg-muted/50"
                                        >
                                            <span className="flex items-center gap-2">
                                                <span className="font-mono font-semibold">{mover.symbol}</span>
                                                {mover.sentiment_label && (
                                                    <Badge variant="outline" className="text-xs text-muted-foreground">
                                                        {mover.sentiment_label.toLowerCase()}
                                                    </Badge>
                                                )}
                                            </span>
                                            <span
                                                className={`flex items-center gap-1 font-mono text-xs ${
                                                    mover.change_pct >= 0 ? 'text-emerald-400' : 'text-red-400'
                                                }`}
                                            >
                                                {mover.change_pct >= 0 ? (
                                                    <ArrowUpRight className="size-3" />
                                                ) : (
                                                    <ArrowDownRight className="size-3" />
                                                )}
                                                {mover.change_pct}%
                                                <span className="text-muted-foreground">({mover.mentions})</span>
                                            </span>
                                        </Link>
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

/**
 * Market regime strip: the same macro features the GBM sees. Penny pumps are
 * a risk-appetite trade — a red regime means signals fire into a dead tape.
 */
function RegimeBanner({ regime }: { regime: Props['regime'] }) {
    const vixTone =
        regime.vix === null ? 'neutral' : regime.vix < 20 ? 'good' : regime.vix < 28 ? 'warn' : 'bad';

    const toneClass = {
        good: 'border-emerald-500/30 bg-emerald-500/5',
        warn: 'border-amber-500/30 bg-amber-500/5',
        bad: 'border-rose-500/40 bg-rose-500/10',
        neutral: 'border-border/60',
    }[vixTone];

    return (
        <div className={cn('flex flex-wrap items-center gap-x-6 gap-y-2 rounded-md border px-4 py-2 text-sm', toneClass)}>
            <span className="flex items-center gap-1 text-xs font-medium text-muted-foreground">
                Market regime
                <InfoTip>
                    The same macro features the model sees. Low VIX and a rising tape keep the pump game alive; VIX
                    above ~28 historically coincided with signals firing into a dead tape.
                </InfoTip>
            </span>
            <RegimeStat
                label="VIX"
                value={regime.vix !== null ? regime.vix.toFixed(1) : '—'}
                accent={
                    vixTone === 'good' ? 'text-emerald-400' : vixTone === 'warn' ? 'text-amber-400' : vixTone === 'bad' ? 'text-rose-400' : undefined
                }
                suffix={vixTone === 'good' ? 'risk-on' : vixTone === 'warn' ? 'jumpy' : vixTone === 'bad' ? 'risk-off' : undefined}
            />
            <RegimeStat label="S&P 5d" value={pct(regime.market_ret_5d)} accent={returnColor(regime.market_ret_5d)} />
            <RegimeStat label="BTC 5d" value={pct(regime.btc_ret_5d)} accent={returnColor(regime.btc_ret_5d)} />
            <RegimeStat
                label="Site buzz"
                value={regime.site_mention_z !== null ? `${regime.site_mention_z >= 0 ? '+' : ''}${regime.site_mention_z.toFixed(1)}σ` : '—'}
            />
        </div>
    );
}

function RegimeStat({ label, value, accent, suffix }: { label: string; value: string; accent?: string; suffix?: string }) {
    return (
        <span className="flex items-baseline gap-1.5">
            <span className="text-xs text-muted-foreground">{label}</span>
            <span className={cn('font-mono font-semibold', accent)}>{value}</span>
            {suffix && <span className={cn('text-[10px]', accent)}>{suffix}</span>}
        </span>
    );
}

function PositionsRail({ positions }: { positions: Position[] }) {
    if (positions.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">
                    Open positions <span className="font-normal text-muted-foreground">— paper, v3 discipline</span>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                {positions.map((p) => {
                    const stopDist =
                        p.last_quote !== null && p.stop_price !== null && p.last_quote > 0
                            ? (p.last_quote - p.stop_price) / p.last_quote
                            : null;

                    return (
                        <Link
                            key={p.id}
                            href={signalShow(p.signal_id)}
                            className="flex items-center justify-between gap-2 rounded-md border border-border/60 px-3 py-2 hover:bg-muted/50"
                        >
                            <span className="flex items-center gap-2">
                                <span className="font-mono font-semibold">{p.symbol}</span>
                                <TradeStatusBadge status={p.status} />
                                {p.status === 'open' && p.holding_day !== null && (
                                    <span className="font-mono text-[10px] text-muted-foreground">d{p.holding_day}/5</span>
                                )}
                            </span>
                            <span className="flex items-center gap-2 font-mono text-xs">
                                {stopDist !== null && stopDist <= 0.03 && (
                                    <span className="text-rose-400">stop {(stopDist * 100).toFixed(1)}%</span>
                                )}
                                <span className={returnColor(p.unrealized_return)}>{pct(p.unrealized_return)}</span>
                            </span>
                        </Link>
                    );
                })}
            </CardContent>
        </Card>
    );
}

function EmptyState({ message }: { message: string }) {
    return <p className="py-6 text-center text-sm text-muted-foreground">{message}</p>;
}

Radar.layout = {
    breadcrumbs: [{ title: 'Radar', href: radar() }],
};
