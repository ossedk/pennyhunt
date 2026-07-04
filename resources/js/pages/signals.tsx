import { Head, Link, router } from '@inertiajs/react';
import { useEchoPublic } from '@laravel/echo-react';
import { useState } from 'react';
import { MarketStatusBadge, relativeTime, TierBadge, TradeStatusBadge } from '@/components/pennyhunt/badges';
import type { MarketStatus } from '@/components/pennyhunt/badges';
import { InfoTip } from '@/components/pennyhunt/info-tip';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { signals } from '@/routes';
import { show as signalShow } from '@/routes/signals';
import { show as tickerShow } from '@/routes/tickers';

type TradeRow = {
    id: number;
    signal_id: number;
    symbol: string;
    name: string | null;
    status: string;
    confidence_at_entry: number | null;
    fired_at: string | null;
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

type SignalRow = {
    id: number;
    symbol: string;
    name: string | null;
    score: number;
    confidence: number | null;
    model_version: string | null;
    state: string;
    breakdown: {
        components?: Record<string, number>;
        market_gate?: { passes: boolean; close: number | null; volume_z: number | null } | null;
    } | null;
    forward_return_1d: number | null;
    forward_return_3d: number | null;
    forward_return_5d: number | null;
    graded_at: string | null;
    fired_at: string;
    trade: { id: number; status: string; net_return: number | null; unrealized_return: number | null } | null;
};

type TradeAlert = {
    id: number;
    kind: string;
    signal_id: number | null;
    payload: Record<string, unknown> & { symbol?: string };
    created_at: string;
};

type Props = {
    positions: TradeRow[];
    closed: TradeRow[];
    signals: { data: SignalRow[] };
    scoreboard: {
        open: number;
        closed: number;
        win_rate: number | null;
        avg_net: number | null;
        total_net: number | null;
        stop_rate: number | null;
        avg_confidence: number | null;
    };
    tradeTier: { raw_p: number; calibrated_p: number } | null;
    tradeAlerts: TradeAlert[];
    marketStatus: MarketStatus | null;
};

const ALERT_LABELS: Record<string, { label: string; tone: string }> = {
    trade_stop_proximity: { label: 'near stop', tone: 'border-rose-500/50 bg-rose-500/10 text-rose-300' },
    trade_time_exit_next: { label: 'time exit next session', tone: 'border-sky-500/40 bg-sky-500/10 text-sky-300' },
    trade_new_filing: { label: 'dilution filing', tone: 'border-amber-500/50 bg-amber-500/10 text-amber-300' },
    trade_mention_collapse: { label: 'buzz collapsed', tone: 'border-amber-500/40 bg-amber-500/5 text-amber-400' },
};

const pct = (v: number | null | undefined, digits = 1) =>
    v === null || v === undefined ? '—' : `${v >= 0 ? '+' : ''}${(v * 100).toFixed(digits)}%`;

const usd = (v: number | null | undefined) =>
    v === null || v === undefined ? '—' : `$${v >= 1 ? v.toFixed(2) : v.toFixed(4)}`;

const returnColor = (v: number | null | undefined) =>
    v === null || v === undefined ? 'text-muted-foreground' : v > 0 ? 'text-emerald-400' : v < 0 ? 'text-rose-400' : 'text-muted-foreground';

type Tab = 'positions' | 'history' | 'log';

export default function Signals({ positions, closed, signals: signalPage, scoreboard, tradeTier, tradeAlerts, marketStatus }: Props) {
    const [tab, setTab] = useState<Tab>(positions.length > 0 ? 'positions' : 'log');

    // Live refresh on trade lifecycle broadcasts (entry fills, exits, quotes).
    useEchoPublic('pennyhunt.trades', '.trade.updated', () => {
        router.reload({ only: ['positions', 'closed', 'scoreboard'] });
    });

    return (
        <>
            <Head title="Signals" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-3">
                    <h1 className="text-lg font-semibold">Signals & trades</h1>
                    <MarketStatusBadge market={marketStatus} />
                    {tradeTier && (
                        <span className="flex items-center gap-1 text-xs text-muted-foreground">
                            trade tier ≥ {(tradeTier.calibrated_p * 100).toFixed(1)}% model probability
                            <InfoTip>Signals at or above the active GBM model's validated trade-tier probability automatically become paper trades, managed with the exact backtested discipline: enter next open, -10% stop, exit at the day-5 close. This forward test is the evidence the strategy must earn before real money.</InfoTip>
                        </span>
                    )}
                </div>

                {/* ── Forward-test scoreboard ───────────────────────────── */}
                <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <StatCard label="Open positions" value={String(scoreboard.open)} />
                    <StatCard label="Closed trades" value={String(scoreboard.closed)} />
                    <StatCard
                        label="Win rate (net)"
                        value={scoreboard.win_rate !== null ? `${(scoreboard.win_rate * 100).toFixed(0)}%` : '—'}
                        hint="Backtest tier: ~42% of trades net positive"
                    />
                    <StatCard
                        label="Avg net / trade"
                        value={pct(scoreboard.avg_net)}
                        accent={returnColor(scoreboard.avg_net)}
                        hint="Backtest tier expectation: +22.9%"
                    />
                    <StatCard label="Total net" value={pct(scoreboard.total_net)} accent={returnColor(scoreboard.total_net)} />
                    <StatCard
                        label="Stopped out"
                        value={scoreboard.stop_rate !== null ? `${(scoreboard.stop_rate * 100).toFixed(0)}%` : '—'}
                        hint="Backtest tier: ~45% hit the -10% stop"
                    />
                </div>

                {/* ── Position risk alerts ──────────────────────────────── */}
                {tradeAlerts.length > 0 && (
                    <div className="flex flex-wrap gap-1.5">
                        {tradeAlerts.map((alert) => {
                            const meta = ALERT_LABELS[alert.kind] ?? { label: alert.kind, tone: 'text-muted-foreground' };
                            const chip = (
                                <Badge key={alert.id} variant="outline" className={cn('font-mono text-[10px]', meta.tone)}>
                                    {alert.payload.symbol ?? '?'} · {meta.label} · {relativeTime(alert.created_at)}
                                </Badge>
                            );

                            return alert.signal_id !== null ? (
                                <Link key={alert.id} href={signalShow(alert.signal_id)}>
                                    {chip}
                                </Link>
                            ) : (
                                chip
                            );
                        })}
                    </div>
                )}

                {/* ── Tabs ──────────────────────────────────────────────── */}
                <div className="flex gap-0.5 self-start rounded-md border border-border/60 p-0.5">
                    {(
                        [
                            ['positions', `Positions (${positions.length})`],
                            ['history', `History (${closed.length})`],
                            ['log', 'Signal log'],
                        ] as [Tab, string][]
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setTab(key)}
                            className={cn(
                                'rounded px-3 py-1 text-xs font-medium transition-colors',
                                tab === key ? 'bg-secondary text-foreground' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                {tab === 'positions' && <PositionsTable trades={positions} />}
                {tab === 'history' && <HistoryTable trades={closed} />}
                {tab === 'log' && <SignalLog signalPage={signalPage} tradeTier={tradeTier} />}
            </div>
        </>
    );
}

/* ── Positions ──────────────────────────────────────────────────────── */

function PositionsTable({ trades }: { trades: TradeRow[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">
                    Open & pending paper positions{' '}
                    <span className="font-normal text-muted-foreground">— click a row for the full cockpit</span>
                </CardTitle>
            </CardHeader>
            <CardContent>
                {trades.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        No open positions. Trades open automatically when a signal clears the model's trade tier.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Ticker</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Conf.</TableHead>
                                <TableHead className="text-right">Entry</TableHead>
                                <TableHead className="text-right">Stop</TableHead>
                                <TableHead className="text-right">Last</TableHead>
                                <TableHead className="text-right">Unrealized</TableHead>
                                <TableHead className="text-right">Stop dist.</TableHead>
                                <TableHead>Day</TableHead>
                                <TableHead className="text-right">Size</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {trades.map((trade) => {
                                const stopDist =
                                    trade.last_quote !== null && trade.stop_price !== null && trade.last_quote > 0
                                        ? (trade.last_quote - trade.stop_price) / trade.last_quote
                                        : null;

                                return (
                                    <TableRow
                                        key={trade.id}
                                        className="cursor-pointer"
                                        onClick={() => router.visit(signalShow(trade.signal_id))}
                                    >
                                        <TableCell>
                                            <span className="font-mono font-semibold">{trade.symbol}</span>
                                        </TableCell>
                                        <TableCell>
                                            <TradeStatusBadge status={trade.status} />
                                        </TableCell>
                                        <TableCell className="text-right font-mono text-emerald-400">
                                            {trade.confidence_at_entry !== null ? `${(trade.confidence_at_entry * 100).toFixed(1)}%` : '—'}
                                        </TableCell>
                                        <TableCell className="text-right font-mono">{usd(trade.entry_price)}</TableCell>
                                        <TableCell className="text-right font-mono text-rose-400/80">{usd(trade.stop_price)}</TableCell>
                                        <TableCell className="text-right font-mono" title={trade.last_quote_at ? relativeTime(trade.last_quote_at) : undefined}>
                                            {usd(trade.last_quote)}
                                        </TableCell>
                                        <TableCell className={cn('text-right font-mono', returnColor(trade.unrealized_return))}>
                                            {pct(trade.unrealized_return)}
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                'text-right font-mono',
                                                stopDist !== null && stopDist <= 0.03 ? 'text-rose-400' : 'text-muted-foreground',
                                            )}
                                        >
                                            {stopDist !== null ? `${(stopDist * 100).toFixed(1)}%` : '—'}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs text-muted-foreground">
                                            {trade.status === 'open' && trade.holding_day !== null ? `${trade.holding_day}/5` : '—'}
                                        </TableCell>
                                        <TableCell className="text-right font-mono text-xs text-muted-foreground">
                                            {trade.kelly_fraction !== null ? `${(trade.kelly_fraction * 100).toFixed(1)}%` : '—'}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

/* ── History ────────────────────────────────────────────────────────── */

function HistoryTable({ trades }: { trades: TradeRow[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">
                    Closed trades <span className="font-normal text-muted-foreground">— the forward-test record, worst friction assumed</span>
                </CardTitle>
            </CardHeader>
            <CardContent>
                {trades.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">No closed trades yet.</p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Ticker</TableHead>
                                <TableHead>Entry</TableHead>
                                <TableHead>Exit</TableHead>
                                <TableHead>Reason</TableHead>
                                <TableHead className="text-right">Conf.</TableHead>
                                <TableHead className="text-right">Entry $</TableHead>
                                <TableHead className="text-right">Exit $</TableHead>
                                <TableHead className="text-right">Gross</TableHead>
                                <TableHead className="text-right">Net</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {trades.map((trade) => (
                                <TableRow key={trade.id} className="cursor-pointer" onClick={() => router.visit(signalShow(trade.signal_id))}>
                                    <TableCell>
                                        <span className="font-mono font-semibold">{trade.symbol}</span>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs text-muted-foreground">{trade.entry_date ?? '—'}</TableCell>
                                    <TableCell className="font-mono text-xs text-muted-foreground">{trade.exit_date ?? '—'}</TableCell>
                                    <TableCell>
                                        <Badge
                                            variant="outline"
                                            className={cn(
                                                'font-mono text-[10px]',
                                                trade.exit_reason === 'stop' ? 'border-rose-500/40 text-rose-400' : 'text-muted-foreground',
                                            )}
                                        >
                                            {trade.exit_reason ?? '—'}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-right font-mono text-xs">
                                        {trade.confidence_at_entry !== null ? `${(trade.confidence_at_entry * 100).toFixed(1)}%` : '—'}
                                    </TableCell>
                                    <TableCell className="text-right font-mono">{usd(trade.entry_price)}</TableCell>
                                    <TableCell className="text-right font-mono">{usd(trade.exit_price)}</TableCell>
                                    <TableCell className={cn('text-right font-mono', returnColor(trade.exit_return))}>{pct(trade.exit_return)}</TableCell>
                                    <TableCell className={cn('text-right font-mono font-semibold', returnColor(trade.net_return))}>
                                        {pct(trade.net_return)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

/* ── Signal log (every fire, traded or not) ─────────────────────────── */

function SignalLog({ signalPage, tradeTier }: { signalPage: { data: SignalRow[] }; tradeTier: Props['tradeTier'] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">
                    Every fired signal{' '}
                    <span className="font-normal text-muted-foreground">— click a row for the cockpit; ungraded rows await price data</span>
                </CardTitle>
            </CardHeader>
            <CardContent>
                {signalPage.data.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        No signals fired yet. Signals fire when a ticker's composite score (mention acceleration, author
                        breadth, sentiment, cross-source confirmation) crosses the threshold.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Ticker</TableHead>
                                <TableHead>Tier</TableHead>
                                <TableHead>Fired</TableHead>
                                <TableHead className="text-right">Score</TableHead>
                                <TableHead className="text-right">Conf.</TableHead>
                                <TableHead>Gate</TableHead>
                                <TableHead>Trade</TableHead>
                                <TableHead className="text-right">+1d</TableHead>
                                <TableHead className="text-right">+3d</TableHead>
                                <TableHead className="text-right">+5d</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {signalPage.data.map((signal) => (
                                <TableRow key={signal.id} className="cursor-pointer" onClick={() => router.visit(signalShow(signal.id))}>
                                    <TableCell>
                                        <Link
                                            href={tickerShow(signal.symbol)}
                                            className="font-mono font-semibold hover:underline"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            {signal.symbol}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <TierBadge confidence={signal.confidence} threshold={tradeTier?.calibrated_p ?? null} />
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">{relativeTime(signal.fired_at)}</TableCell>
                                    <TableCell className="text-right font-mono text-emerald-400">{(signal.score * 100).toFixed(0)}</TableCell>
                                    <TableCell className="text-right font-mono" title={signal.model_version ?? undefined}>
                                        {signal.confidence !== null ? (
                                            <span
                                                className={cn(
                                                    tradeTier && signal.confidence >= tradeTier.calibrated_p
                                                        ? 'text-emerald-400'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {(signal.confidence * 100).toFixed(1)}%
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <GateBadge gate={signal.breakdown?.market_gate} />
                                    </TableCell>
                                    <TableCell>
                                        {signal.trade ? (
                                            <span className="flex items-center gap-1.5">
                                                <TradeStatusBadge status={signal.trade.status} />
                                                {signal.trade.status === 'closed' && (
                                                    <span className={cn('font-mono text-xs', returnColor(signal.trade.net_return))}>
                                                        {pct(signal.trade.net_return)}
                                                    </span>
                                                )}
                                            </span>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">—</span>
                                        )}
                                    </TableCell>
                                    <ReturnCell value={signal.forward_return_1d} />
                                    <ReturnCell value={signal.forward_return_3d} />
                                    <ReturnCell value={signal.forward_return_5d} />
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

function GateBadge({
    gate,
}: {
    gate: { passes: boolean; close: number | null; volume_z: number | null } | null | undefined;
}) {
    if (!gate) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    return (
        <Badge
            variant="outline"
            className={cn(
                'font-mono text-[10px]',
                gate.passes ? 'border-emerald-500/40 text-emerald-400' : 'border-red-500/40 text-red-400',
            )}
            title="Market-confirmation gate: latest close and volume z-score at fire time"
        >
            {gate.close !== null ? `$${gate.close.toFixed(2)}` : '?'}
            {gate.volume_z !== null ? ` · vz ${gate.volume_z.toFixed(1)}` : ''}
        </Badge>
    );
}

function StatCard({ label, value, hint, accent }: { label: string; value: string; hint?: string; accent?: string }) {
    return (
        <Card className="py-4">
            <CardContent className="px-4">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className={cn('mt-1 font-mono text-2xl font-semibold', accent)}>{value}</p>
                {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
            </CardContent>
        </Card>
    );
}

function ReturnCell({ value }: { value: number | null }) {
    if (value === null) {
        return <TableCell className="text-right text-xs text-muted-foreground">—</TableCell>;
    }

    return (
        <TableCell
            className={cn(
                'text-right font-mono',
                value > 0 ? 'text-emerald-400' : value < 0 ? 'text-red-400' : 'text-muted-foreground',
            )}
        >
            {value > 0 ? '+' : ''}
            {(value * 100).toFixed(1)}%
        </TableCell>
    );
}

Signals.layout = {
    breadcrumbs: [{ title: 'Signals', href: signals() }],
};
