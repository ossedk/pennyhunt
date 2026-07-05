import { Head, Link, router, useForm } from '@inertiajs/react';
import { FlaskConical, Loader2, Play } from 'lucide-react';
import { useEffect } from 'react';
import { CartesianGrid, Line, LineChart, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { backtests } from '@/routes';
import { store as backtestsStore } from '@/routes/backtests';
import { show as tickerShow } from '@/routes/tickers';

type BacktestEvent = {
    id: number;
    symbol: string;
    day: string;
    composite: number;
    zscore: number;
    mentions: number;
    unique_authors: number;
    sentiment: number | null;
    volume_z: number | null;
    dollar_volume: number | null;
    pre_return_3d: number | null;
    entry_date: string;
    entry: string;
    return_1d: number;
    return_3d: number;
    return_5d: number;
    best_close_5d: number;
    exit_return: number | null;
    exit_reason: 'stop' | 'take' | 'time' | null;
    exit_day: number | null;
    confidence: number | null;
    hit: boolean;
    classification: 'reaction' | 'prediction';
};

type PortfolioStrategy = {
    final_equity: number;
    total_return: number;
    max_drawdown: number;
    trades_taken: number;
    trades_skipped: number;
    liquidity_capped: number;
    avg_position_pct: number | null;
};

type PortfolioResult = {
    trades: number;
    friction: number;
    options: { initial_equity: number };
    strategies: Record<string, PortfolioStrategy>;
    curves: ({ date: string } & Record<string, number | string>)[];
};

type ConfidenceResult = {
    events_scored: number;
    events_total: number;
    brier: number | null;
    brier_reference: number | null;
    base_rate: number | null;
    reliability: { count: number; predicted: number; realized: number }[];
};

type RunSummary = {
    signal_count: number;
    hit_rate: number | null;
    base_rate: number | null;
    avg_return_5d: number | null;
    median_return_5d: number | null;
    control_avg_return_5d: number | null;
    prediction_share: number | null;
    prediction_hit_rate: number | null;
    reaction_hit_rate: number | null;
    friction: number | null;
    stop_loss: number | null;
    take_profit: number | null;
    avg_exit_return: number | null;
    median_exit_return: number | null;
    stop_rate: number | null;
    take_rate: number | null;
    time_exit_rate: number | null;
    avg_net_return_5d: number | null;
    net_positive_5d_rate: number | null;
    profit_factor: number | null;
};

type ProfileSide = {
    count: number;
    median_entry_price: number | null;
    median_dollar_volume: number | null;
    median_volume_z: number | null;
    median_mention_z: number | null;
    median_mentions: number | null;
    median_sentiment: number | null;
    median_pre_return_3d: number | null;
};

type Run = {
    id: number;
    name: string | null;
    status: 'pending' | 'running' | 'done' | 'failed';
    params: Record<string, string | number>;
    results: {
        summary: RunSummary;
        winner_profile: { winners: ProfileSide; losers: ProfileSide } | null;
        portfolio?: PortfolioResult;
        confidence?: ConfidenceResult;
        meta: Record<string, string | number>;
    } | null;
    error: string | null;
    created_at: string;
    finished_at: string | null;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type ModelRow = {
    id: number;
    version: string;
    backtest_run_id: number | null;
    is_active: boolean;
    train_events: number | null;
    created_at: string;
    brier: number | null;
    brier_reference: number | null;
    base_rate: number | null;
    oos_events: number | null;
    trade_tier: { raw_p: number; calibrated_p: number } | null;
};

type Props = {
    runs: Run[];
    selectedRunId: number | null;
    events: Paginated<BacktestEvent> | null;
    dataCoverage: {
        first_mention: string | null;
        last_mention: string | null;
        mention_count: number;
        tickers_with_bars: number;
    };
    models: ModelRow[];
};

export default function Backtests({ runs, selectedRunId, events, dataCoverage, models }: Props) {
    const hasActive = runs.some((r) => r.status === 'pending' || r.status === 'running');

    useEffect(() => {
        if (!hasActive) {
            return;
        }

        const timer = setInterval(() => router.reload({ only: ['runs', 'events'] }), 4000);

        return () => clearInterval(timer);
    }, [hasActive]);

    const selected = runs.find((r) => r.id === selectedRunId) ?? null;

    return (
        <>
            <Head title="Backtests" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-lg font-semibold">Backtests</h1>
                    <p className="text-xs text-muted-foreground">
                        Archive: {dataCoverage.mention_count.toLocaleString()} mentions
                        {dataCoverage.first_mention && <> since {dataCoverage.first_mention.slice(0, 10)}</>} ·{' '}
                        {dataCoverage.tickers_with_bars} tickers with price bars
                    </p>
                </div>

                <NewRunForm />

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-sm">
                            <FlaskConical className="size-4 text-emerald-400" />
                            Runs
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {runs.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                No runs yet. Configure one above — it replays archived posts through the exact
                                production scoring (as-of baselines, next-day-open entries) and grades every simulated
                                signal against real daily bars.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Run</TableHead>
                                        <TableHead>Window</TableHead>
                                        <TableHead className="text-right">Threshold</TableHead>
                                        <TableHead className="text-right">Gates</TableHead>
                                        <TableHead className="text-right">Signals</TableHead>
                                        <TableHead className="text-right">Hit rate</TableHead>
                                        <TableHead className="text-right">Base rate</TableHead>
                                        <TableHead className="text-right">Net 5d</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {runs.map((run) => (
                                        <TableRow
                                            key={run.id}
                                            className={cn(
                                                'cursor-pointer',
                                                run.id === selectedRunId && 'bg-muted/50',
                                            )}
                                            onClick={() =>
                                                router.get(
                                                    backtests().url,
                                                    { run: run.id },
                                                    { preserveScroll: true, preserveState: true },
                                                )
                                            }
                                        >
                                            <TableCell className="font-mono text-xs">
                                                #{run.id}
                                                {run.name && (
                                                    <span className="ml-1 text-muted-foreground">{run.name}</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                {String(run.params.from)} → {String(run.params.to)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-xs">
                                                {Number(run.params.threshold).toFixed(2)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-[10px] text-muted-foreground">
                                                {[
                                                    run.params.min_volume_z != null && `volz≥${run.params.min_volume_z}`,
                                                    run.params.max_pre_run != null && `pre≤${Number(run.params.max_pre_run) * 100}%`,
                                                    run.params.max_entry_price != null && `px≤$${run.params.max_entry_price}`,
                                                    run.params.stop_loss != null && `stop${Number(run.params.stop_loss) * 100}%`,
                                                    run.params.take_profit != null && `take${Number(run.params.take_profit) * 100}%`,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' ') || '—'}
                                            </TableCell>
                                            <TableCell className="text-right font-mono">
                                                {run.results?.summary.signal_count ?? '—'}
                                            </TableCell>
                                            <PercentCell value={run.results?.summary.hit_rate ?? null} />
                                            <PercentCell value={run.results?.summary.base_rate ?? null} muted />
                                            <PercentCell value={run.results?.summary.avg_net_return_5d ?? null} signed />
                                            <TableCell>
                                                <StatusBadge status={run.status} />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <ModelRegistry models={models} />

                {selected?.status === 'failed' && (
                    <Card>
                        <CardContent className="py-4 text-sm text-red-400">{selected.error}</CardContent>
                    </Card>
                )}

                {selected?.results && <RunSummaryCards summary={selected.results.summary} />}
                {selected?.results?.portfolio && (
                    <PortfolioPanel portfolio={selected.results.portfolio} confidence={selected.results.confidence} />
                )}
                {selected?.results?.winner_profile && <WinnerProfile profile={selected.results.winner_profile} />}
                {selected && events && <SignalsTable events={events} />}
            </div>
        </>
    );
}

/**
 * Where the nightly shadow GBM retrains land. Lower Brier = better
 * calibrated skill; "edge vs base" shows the improvement over always
 * predicting the base rate.
 */
function ModelRegistry({ models }: { models: ModelRow[] }) {
    if (models.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">Model registry — nightly GBM retrains</CardTitle>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Version</TableHead>
                            <TableHead>Trained on</TableHead>
                            <TableHead className="text-right">Train events</TableHead>
                            <TableHead className="text-right">OOS events</TableHead>
                            <TableHead className="text-right">Brier ↓</TableHead>
                            <TableHead className="text-right">Edge vs base</TableHead>
                            <TableHead className="text-right">Trade tier p≥</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {models.map((m) => {
                            const edge =
                                m.brier !== null && m.brier_reference !== null
                                    ? (1 - m.brier / m.brier_reference) * 100
                                    : null;

                            return (
                                <TableRow key={m.id}>
                                    <TableCell className="font-mono text-xs">{m.version}</TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        run #{m.backtest_run_id} · {m.created_at.slice(0, 10)}
                                    </TableCell>
                                    <TableCell className="text-right font-mono text-xs">
                                        {m.train_events?.toLocaleString() ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-right font-mono text-xs">
                                        {m.oos_events?.toLocaleString() ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-right font-mono text-xs">
                                        {m.brier !== null ? m.brier.toFixed(5) : '—'}
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            'text-right font-mono text-xs',
                                            edge !== null && edge > 0
                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {edge !== null ? `+${edge.toFixed(1)}%` : '—'}
                                    </TableCell>
                                    <TableCell className="text-right font-mono text-xs">
                                        {m.trade_tier ? m.trade_tier.raw_p.toFixed(2) : '—'}
                                    </TableCell>
                                    <TableCell>
                                        {m.is_active ? (
                                            <Badge className="bg-emerald-500/15 text-xs text-emerald-600 dark:text-emerald-400">
                                                active
                                            </Badge>
                                        ) : (
                                            <Badge variant="outline" className="text-xs text-muted-foreground">
                                                shadow
                                            </Badge>
                                        )}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function NewRunForm() {
    const form = useForm({
        from: '2026-01-03',
        to: '2026-06-24',
        threshold: '0.65',
        min_daily_mentions: '3',
        hit_threshold: '0.30',
        friction: '0.05',
        min_volume_z: '',
        max_pre_run: '',
        max_entry_price: '',
        stop_loss: '',
        take_profit: '',
    });

    return (
        <Card>
            <CardContent className="py-4">
                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            min_volume_z: data.min_volume_z === '' ? null : data.min_volume_z,
                            max_pre_run: data.max_pre_run === '' ? null : data.max_pre_run,
                            max_entry_price: data.max_entry_price === '' ? null : data.max_entry_price,
                            stop_loss: data.stop_loss === '' ? null : data.stop_loss,
                            take_profit: data.take_profit === '' ? null : data.take_profit,
                        }));
                        form.post(backtestsStore().url, { preserveScroll: true });
                    }}
                >
                    <Field label="From">
                        <Input
                            type="date"
                            value={form.data.from}
                            onChange={(e) => form.setData('from', e.target.value)}
                            className="w-38"
                        />
                    </Field>
                    <Field label="To">
                        <Input
                            type="date"
                            value={form.data.to}
                            onChange={(e) => form.setData('to', e.target.value)}
                            className="w-38"
                        />
                    </Field>
                    <Field label="Score threshold">
                        <Input
                            type="number"
                            step="0.05"
                            min="0"
                            max="1"
                            value={form.data.threshold}
                            onChange={(e) => form.setData('threshold', e.target.value)}
                            className="w-22"
                        />
                    </Field>
                    <Field label="Min mentions/day">
                        <Input
                            type="number"
                            min="1"
                            value={form.data.min_daily_mentions}
                            onChange={(e) => form.setData('min_daily_mentions', e.target.value)}
                            className="w-22"
                        />
                    </Field>
                    <Field label="Hit ≥">
                        <Input
                            type="number"
                            step="0.05"
                            min="0.05"
                            value={form.data.hit_threshold}
                            onChange={(e) => form.setData('hit_threshold', e.target.value)}
                            className="w-20"
                        />
                    </Field>
                    <Field label="Friction (round-trip)">
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            max="0.5"
                            value={form.data.friction}
                            onChange={(e) => form.setData('friction', e.target.value)}
                            className="w-22"
                        />
                    </Field>
                    <Field label="Gate: vol z ≥ (opt)">
                        <Input
                            type="number"
                            step="0.5"
                            min="0"
                            value={form.data.min_volume_z}
                            onChange={(e) => form.setData('min_volume_z', e.target.value)}
                            className="w-22"
                            placeholder="off"
                        />
                    </Field>
                    <Field label="Gate: pre-run ≤ (opt)">
                        <Input
                            type="number"
                            step="0.05"
                            min="0"
                            value={form.data.max_pre_run}
                            onChange={(e) => form.setData('max_pre_run', e.target.value)}
                            className="w-22"
                            placeholder="off"
                        />
                    </Field>
                    <Field label="Gate: price ≤ $ (opt)">
                        <Input
                            type="number"
                            step="1"
                            min="0.01"
                            value={form.data.max_entry_price}
                            onChange={(e) => form.setData('max_entry_price', e.target.value)}
                            className="w-22"
                            placeholder="off"
                        />
                    </Field>
                    <Field label="Stop loss (opt)">
                        <Input
                            type="number"
                            step="0.05"
                            min="0.01"
                            max="0.9"
                            value={form.data.stop_loss}
                            onChange={(e) => form.setData('stop_loss', e.target.value)}
                            className="w-22"
                            placeholder="off"
                        />
                    </Field>
                    <Field label="Take profit (opt)">
                        <Input
                            type="number"
                            step="0.05"
                            min="0.01"
                            value={form.data.take_profit}
                            onChange={(e) => form.setData('take_profit', e.target.value)}
                            className="w-22"
                            placeholder="off"
                        />
                    </Field>
                    <Button type="submit" disabled={form.processing} size="sm" className="gap-1.5">
                        {form.processing ? <Loader2 className="size-3.5 animate-spin" /> : <Play className="size-3.5" />}
                        Run backtest
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function RunSummaryCards({ summary }: { summary: RunSummary }) {
    return (
        <div className="grid gap-4 md:grid-cols-4">
            <StatCard label="Simulated signals" value={String(summary.signal_count)} />
            <StatCard
                label="Hit rate (≥ target in 5d)"
                value={pct(summary.hit_rate)}
                hint={`Base rate: ${pct(summary.base_rate)} · reactions ${pct(summary.reaction_hit_rate)} / predictions ${pct(summary.prediction_hit_rate)}`}
                highlight={
                    summary.hit_rate !== null && summary.base_rate !== null && summary.hit_rate > summary.base_rate
                }
            />
            <StatCard
                label="Avg 5d return (gross)"
                value={pct(summary.avg_return_5d)}
                hint={`Median ${pct(summary.median_return_5d)} · control ${pct(summary.control_avg_return_5d)}`}
            />
            <StatCard
                label={`Net/trade after ${pct(summary.friction)} friction${summary.stop_loss != null || summary.take_profit != null ? ` (stop ${pct(summary.stop_loss)} / take ${pct(summary.take_profit)})` : ''}`}
                value={pct(summary.avg_net_return_5d)}
                hint={`Net win rate ${pct(summary.net_positive_5d_rate)} · profit factor ${summary.profit_factor ?? '—'}${summary.stop_rate != null && (summary.stop_loss != null || summary.take_profit != null) ? ` · exits: ${pct(summary.stop_rate)} stop / ${pct(summary.take_rate)} take / ${pct(summary.time_exit_rate)} time` : ''}`}
                highlight={summary.avg_net_return_5d !== null && summary.avg_net_return_5d > 0}
            />
        </div>
    );
}

const strategyMeta: Record<string, { label: string; color: string }> = {
    equal: { label: 'Equal weight (5%)', color: '#71717a' },
    kelly_half: { label: 'Half Kelly', color: '#10b981' },
    kelly_full: { label: 'Full Kelly', color: '#f59e0b' },
};

function PortfolioPanel({ portfolio, confidence }: { portfolio: PortfolioResult; confidence?: ConfidenceResult }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">
                    Portfolio simulation{' '}
                    <span className="font-normal text-muted-foreground">
                        — {portfolio.trades} confidence-scored trades, sized by walk-forward P(hit); positions capped
                        at 10% of equity and 1% of signal-day dollar volume
                        {confidence?.brier != null &&
                            ` · confidence Brier ${confidence.brier} (base-rate reference ${confidence.brier_reference})`}
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Strategy</TableHead>
                            <TableHead className="text-right">Final equity</TableHead>
                            <TableHead className="text-right">Return</TableHead>
                            <TableHead className="text-right">Max drawdown</TableHead>
                            <TableHead className="text-right">Taken</TableHead>
                            <TableHead className="text-right">Skipped</TableHead>
                            <TableHead className="text-right">Liquidity-capped</TableHead>
                            <TableHead className="text-right">Avg position</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {Object.entries(portfolio.strategies).map(([name, strategy]) => (
                            <TableRow key={name}>
                                <TableCell className="text-xs" style={{ color: strategyMeta[name]?.color }}>
                                    {strategyMeta[name]?.label ?? name}
                                </TableCell>
                                <TableCell className="text-right font-mono text-xs">
                                    ${strategy.final_equity.toLocaleString(undefined, { maximumFractionDigits: 0 })}
                                </TableCell>
                                <PercentCell value={strategy.total_return} signed />
                                <TableCell className="text-right font-mono text-xs text-red-400">
                                    −{(strategy.max_drawdown * 100).toFixed(1)}%
                                </TableCell>
                                <TableCell className="text-right font-mono text-xs">{strategy.trades_taken}</TableCell>
                                <TableCell className="text-right font-mono text-xs text-muted-foreground">
                                    {strategy.trades_skipped}
                                </TableCell>
                                <TableCell className="text-right font-mono text-xs text-muted-foreground">
                                    {strategy.liquidity_capped}
                                </TableCell>
                                <TableCell className="text-right font-mono text-xs">
                                    {strategy.avg_position_pct !== null
                                        ? `${(strategy.avg_position_pct * 100).toFixed(1)}%`
                                        : '—'}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>

                <ResponsiveContainer width="100%" height={260}>
                    <LineChart data={portfolio.curves}>
                        <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.06)" />
                        <XAxis dataKey="date" tick={{ fontSize: 11, fill: '#71717a' }} minTickGap={32} />
                        <YAxis
                            domain={['auto', 'auto']}
                            tick={{ fontSize: 11, fill: '#71717a' }}
                            tickFormatter={(v: number) => `$${(v / 1000).toFixed(0)}k`}
                            width={56}
                        />
                        <Tooltip
                            contentStyle={{
                                backgroundColor: '#18181b',
                                border: '1px solid #27272a',
                                borderRadius: 8,
                                fontSize: 12,
                            }}
                            formatter={(value, name) => [
                                `$${Number(value).toLocaleString(undefined, { maximumFractionDigits: 0 })}`,
                                strategyMeta[String(name)]?.label ?? String(name),
                            ]}
                        />
                        <Legend formatter={(name: string) => strategyMeta[name]?.label ?? name} />
                        {Object.keys(portfolio.strategies).map((name) => (
                            <Line
                                key={name}
                                type="stepAfter"
                                dataKey={name}
                                stroke={strategyMeta[name]?.color ?? '#71717a'}
                                strokeWidth={1.5}
                                dot={false}
                            />
                        ))}
                    </LineChart>
                </ResponsiveContainer>

                {confidence && confidence.reliability.length > 0 && (
                    <p className="text-xs text-muted-foreground">
                        Calibration (predicted → realized hit rate by quintile):{' '}
                        {confidence.reliability
                            .map((b) => `${(b.predicted * 100).toFixed(1)}%→${(b.realized * 100).toFixed(1)}%`)
                            .join(' · ')}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function WinnerProfile({ profile }: { profile: { winners: ProfileSide; losers: ProfileSide } }) {
    const rows: { label: string; key: keyof ProfileSide; fmt: (v: number | null) => string }[] = [
        { label: 'Entry price', key: 'median_entry_price', fmt: (v) => (v !== null ? `$${v.toFixed(2)}` : '—') },
        { label: 'Dollar volume (signal day)', key: 'median_dollar_volume', fmt: money },
        { label: 'Volume z-score', key: 'median_volume_z', fmt: num },
        { label: 'Mention z-score', key: 'median_mention_z', fmt: num },
        { label: 'Mentions', key: 'median_mentions', fmt: num },
        { label: 'Sentiment', key: 'median_sentiment', fmt: num },
        { label: 'Pre-entry 3d run-up', key: 'median_pre_return_3d', fmt: pct },
    ];

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">
                    Winner profile{' '}
                    <span className="font-normal text-muted-foreground">
                        — feature medians: what separates ≥-target winners from the rest of the fired signals
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Feature</TableHead>
                            <TableHead className="text-right">
                                Winners ({profile.winners.count})
                            </TableHead>
                            <TableHead className="text-right text-muted-foreground">
                                Losers ({profile.losers.count})
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.key}>
                                <TableCell className="text-xs">{row.label}</TableCell>
                                <TableCell className="text-right font-mono text-xs text-emerald-400">
                                    {row.fmt(profile.winners[row.key] as number | null)}
                                </TableCell>
                                <TableCell className="text-right font-mono text-xs text-muted-foreground">
                                    {row.fmt(profile.losers[row.key] as number | null)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function SignalsTable({ events }: { events: Paginated<BacktestEvent> }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center justify-between text-sm">
                    <span>
                        Simulated signals ({events.total.toLocaleString()}, newest first){' '}
                        <span className="font-normal text-muted-foreground">
                            — entry at next open; "reaction" = ran &gt;15% in prior 3 sessions
                        </span>
                    </span>
                    <span className="flex items-center gap-2 text-xs font-normal text-muted-foreground">
                        page {events.current_page} / {events.last_page}
                        <PageButton url={events.prev_page_url} label="‹ Prev" />
                        <PageButton url={events.next_page_url} label="Next ›" />
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead>Ticker</TableHead>
                            <TableHead className="text-right">Score</TableHead>
                            <TableHead className="text-right">Conf.</TableHead>
                            <TableHead className="text-right">Mention z</TableHead>
                            <TableHead className="text-right">Vol z</TableHead>
                            <TableHead className="text-right">Entry</TableHead>
                            <TableHead className="text-right">+1d</TableHead>
                            <TableHead className="text-right">+3d</TableHead>
                            <TableHead className="text-right">+5d</TableHead>
                            <TableHead className="text-right">Best 5d</TableHead>
                            <TableHead className="text-right">Exit</TableHead>
                            <TableHead>Type</TableHead>
                            <TableHead>Hit</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {events.data.map((s) => (
                            <TableRow key={s.id}>
                                <TableCell className="font-mono text-xs">{s.day}</TableCell>
                                <TableCell>
                                    <Link
                                        href={tickerShow(s.symbol)}
                                        className="font-mono font-semibold hover:underline"
                                    >
                                        {s.symbol}
                                    </Link>
                                </TableCell>
                                <TableCell className="text-right font-mono">{(s.composite * 100).toFixed(0)}</TableCell>
                                <TableCell className="text-right font-mono text-xs">
                                    {s.confidence !== null ? `${(s.confidence * 100).toFixed(1)}%` : '—'}
                                </TableCell>
                                <TableCell className="text-right font-mono text-xs">{s.zscore.toFixed(1)}</TableCell>
                                <TableCell className="text-right font-mono text-xs">
                                    {s.volume_z !== null ? s.volume_z.toFixed(1) : '—'}
                                </TableCell>
                                <TableCell className="text-right font-mono text-xs">
                                    ${Number(s.entry).toFixed(2)}
                                </TableCell>
                                <ReturnCell value={s.return_1d} />
                                <ReturnCell value={s.return_3d} />
                                <ReturnCell value={s.return_5d} />
                                <ReturnCell value={s.best_close_5d} />
                                <TableCell className="text-right font-mono text-xs">
                                    {s.exit_return !== null && s.exit_reason !== 'time' ? (
                                        <span className={s.exit_return > 0 ? 'text-emerald-400' : 'text-red-400'}>
                                            {s.exit_return > 0 ? '+' : ''}
                                            {(s.exit_return * 100).toFixed(1)}% {s.exit_reason}@{s.exit_day}d
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">—</span>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        variant="outline"
                                        className={cn(
                                            'text-[10px]',
                                            s.classification === 'prediction' ? 'text-emerald-400' : 'text-amber-400',
                                        )}
                                    >
                                        {s.classification}
                                    </Badge>
                                </TableCell>
                                <TableCell>{s.hit ? '✅' : '—'}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function PageButton({ url, label }: { url: string | null; label: string }) {
    return (
        <Button
            variant="outline"
            size="sm"
            className="h-6 px-2 text-xs"
            disabled={!url}
            onClick={() => url && router.get(url, {}, { preserveScroll: true, preserveState: true })}
        >
            {label}
        </Button>
    );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">{label}</Label>
            {children}
        </div>
    );
}

function StatCard({
    label,
    value,
    hint,
    highlight,
}: {
    label: string;
    value: string;
    hint?: string;
    highlight?: boolean;
}) {
    return (
        <Card className="py-4">
            <CardContent className="px-4">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className={cn('mt-1 font-mono text-2xl font-semibold', highlight && 'text-emerald-400')}>{value}</p>
                {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
            </CardContent>
        </Card>
    );
}

function StatusBadge({ status }: { status: Run['status'] }) {
    const styles: Record<Run['status'], string> = {
        pending: 'text-muted-foreground',
        running: 'text-sky-400',
        done: 'text-emerald-400',
        failed: 'text-red-400',
    };

    return (
        <Badge variant="outline" className={cn('gap-1 text-[10px]', styles[status])}>
            {(status === 'running' || status === 'pending') && <Loader2 className="size-2.5 animate-spin" />}
            {status}
        </Badge>
    );
}

function ReturnCell({ value }: { value: number | null }) {
    if (value === null) {
        return <TableCell className="text-right text-xs text-muted-foreground">—</TableCell>;
    }

    return (
        <TableCell
            className={cn(
                'text-right font-mono text-xs',
                value > 0 ? 'text-emerald-400' : value < 0 ? 'text-red-400' : 'text-muted-foreground',
            )}
        >
            {value > 0 ? '+' : ''}
            {(value * 100).toFixed(1)}%
        </TableCell>
    );
}

function PercentCell({ value, muted, signed }: { value: number | null; muted?: boolean; signed?: boolean }) {
    return (
        <TableCell
            className={cn(
                'text-right font-mono text-xs',
                muted && 'text-muted-foreground',
                signed && value !== null && (value > 0 ? 'text-emerald-400' : 'text-red-400'),
            )}
        >
            {signed && value !== null && value > 0 ? '+' : ''}
            {pct(value)}
        </TableCell>
    );
}

function pct(value: number | null): string {
    return value !== null ? `${(value * 100).toFixed(1)}%` : '—';
}

function num(value: number | null): string {
    return value !== null ? value.toFixed(1) : '—';
}

function money(value: number | null): string {
    if (value === null) {
        return '—';
    }

    if (value >= 1_000_000) {
        return `$${(value / 1_000_000).toFixed(1)}M`;
    }

    return `$${(value / 1_000).toFixed(0)}K`;
}

Backtests.layout = {
    breadcrumbs: [{ title: 'Backtests', href: backtests() }],
};
