import { Head, Link } from '@inertiajs/react';
import { ChevronDown, ExternalLink, Trophy } from 'lucide-react';
import { Fragment, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { voices } from '@/routes';
import { show as tickerShow } from '@/routes/tickers';
import { cn } from '@/lib/utils';

type RecentCall = {
    symbol: string | null;
    called_at: string;
    outcome: 'win' | 'flat' | 'loss';
    peak_return: number | null;
    day5_return: number | null;
    runup_3d: number | null;
    permalink: string | null;
};

type Row = {
    rank: number;
    author: {
        username: string | null;
        karma: number | null;
        pump_risk_score: number | null;
        account_created_at: string | null;
    };
    calls: number;
    wins: number;
    flats: number;
    losses: number;
    hit_rate: number;
    wilson_lb: number;
    avg_peak_return: number | null;
    best_peak_return: number | null;
    best_call: { symbol: string; peak_return: number; called_at: string } | null;
    top_tickers: { symbol: string; calls: number; wins: number }[] | null;
    recent_calls: RecentCall[] | null;
};

type Props = {
    week: string | null;
    boards: { reddit: Row[]; twitter: Row[] };
    thresholds: {
        win: number;
        loss: number;
        horizon: number;
        min_calls: number;
        min_calls_twitter: number;
        active_days: number;
    };
};

type Platform = 'reddit' | 'twitter';

const pct = (value: number | null | undefined, digits = 0) =>
    value === null || value === undefined ? '—' : `${value >= 0 ? '+' : ''}${(value * 100).toFixed(digits)}%`;

function OutcomeBadge({ outcome }: { outcome: RecentCall['outcome'] }) {
    const styles = {
        win: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        flat: 'bg-muted text-muted-foreground',
        loss: 'bg-red-500/15 text-red-600 dark:text-red-400',
    } as const;

    return <Badge className={cn('font-mono text-[10px] uppercase', styles[outcome])}>{outcome}</Badge>;
}

export default function Voices({ week, boards, thresholds }: Props) {
    const [expanded, setExpanded] = useState<number | null>(null);
    const [platform, setPlatform] = useState<Platform>('reddit');
    const rows = boards[platform];
    const minCalls = platform === 'twitter' ? thresholds.min_calls_twitter : thresholds.min_calls;

    return (
        <>
            <Head title="Voices" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h1 className="flex items-center gap-2 text-lg font-semibold">
                            <Trophy className="size-5 text-amber-500" />
                            Voices — who actually calls the winners
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Authors ranked by graded track record. A call is the author's first bullish post on a
                            ticker, priced at the next session open; a win means the stock gained {pct(thresholds.win)}{' '}
                            within {thresholds.horizon} sessions. Ranked by Wilson lower bound so small lucky samples
                            can't top the board. Minimum {minCalls} graded calls, and only{' '}
                            <span className="text-foreground">active authors</span> (posted within{' '}
                            {thresholds.active_days} days) rank — dormant accounts drop off.
                        </p>
                    </div>
                    {week && (
                        <Badge variant="outline" className="font-mono text-xs">
                            week of {week}
                        </Badge>
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <CardTitle className="text-base">
                                    Top {rows.length} {platform === 'reddit' ? 'Reddit' : 'X / Twitter'} voices
                                </CardTitle>
                                <CardDescription>
                                    Click a row to see the author's recent graded calls. Run-up shows how much the
                                    stock had already moved in the 3 sessions before the call — low run-up means
                                    genuinely early, high run-up means momentum chasing.
                                </CardDescription>
                            </div>
                            <div className="flex gap-0.5 rounded-md border border-border/60 p-0.5">
                                {(['reddit', 'twitter'] as const).map((p) => (
                                    <button
                                        key={p}
                                        type="button"
                                        onClick={() => {
                                            setPlatform(p);
                                            setExpanded(null);
                                        }}
                                        className={cn(
                                            'rounded px-3 py-1 text-xs font-medium transition-colors',
                                            platform === p
                                                ? 'bg-secondary text-foreground'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {p === 'reddit' ? 'Reddit' : 'X / Twitter'}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {rows.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                {platform === 'twitter'
                                    ? `No ranked X voices yet — X coverage grows with on-demand pulls, and authors need ${minCalls} graded calls plus activity in the last ${thresholds.active_days} days.`
                                    : 'No leaderboard yet — the weekly build runs Monday mornings.'}
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-10">#</TableHead>
                                        <TableHead>Author</TableHead>
                                        <TableHead className="text-right">Hit rate</TableHead>
                                        <TableHead className="text-right">W / F / L</TableHead>
                                        <TableHead className="text-right">Confidence</TableHead>
                                        <TableHead className="text-right">Avg peak</TableHead>
                                        <TableHead>Best call</TableHead>
                                        <TableHead>Favorite tickers</TableHead>
                                        <TableHead className="w-8" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <Fragment key={row.rank}>
                                            <TableRow
                                                className="cursor-pointer"
                                                onClick={() => setExpanded(expanded === row.rank ? null : row.rank)}
                                            >
                                                <TableCell className="font-mono text-muted-foreground">
                                                    {row.rank}
                                                </TableCell>
                                                <TableCell>
                                                    <span className="font-semibold">
                                                        {platform === 'reddit' ? 'u/' : '@'}
                                                        {row.author.username}
                                                    </span>
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        {row.author.karma !== null &&
                                                            `${Intl.NumberFormat('en', { notation: 'compact' }).format(row.author.karma)} ${platform === 'reddit' ? 'karma' : 'followers'}`}
                                                    </span>
                                                    {(row.author.pump_risk_score ?? 0) >= 0.5 && (
                                                        <Badge className="ml-2 bg-orange-500/15 text-[10px] text-orange-600 dark:text-orange-400">
                                                            pump risk
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right font-mono font-semibold">
                                                    {(row.hit_rate * 100).toFixed(0)}%
                                                </TableCell>
                                                <TableCell className="text-right font-mono text-sm">
                                                    <span className="text-emerald-600 dark:text-emerald-400">
                                                        {row.wins}
                                                    </span>
                                                    {' / '}
                                                    <span className="text-muted-foreground">{row.flats}</span>
                                                    {' / '}
                                                    <span className="text-red-600 dark:text-red-400">
                                                        {row.losses}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-right font-mono text-sm text-muted-foreground">
                                                    {(row.wilson_lb * 100).toFixed(0)}%
                                                </TableCell>
                                                <TableCell className="text-right font-mono">
                                                    {pct(row.avg_peak_return)}
                                                </TableCell>
                                                <TableCell>
                                                    {row.best_call ? (
                                                        <span className="font-mono text-sm">
                                                            <Link
                                                                href={tickerShow(row.best_call.symbol)}
                                                                className="font-semibold hover:underline"
                                                                onClick={(e) => e.stopPropagation()}
                                                            >
                                                                ${row.best_call.symbol}
                                                            </Link>{' '}
                                                            <span className="text-emerald-600 dark:text-emerald-400">
                                                                {pct(row.best_call.peak_return)}
                                                            </span>
                                                        </span>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap gap-1">
                                                        {(row.top_tickers ?? []).slice(0, 4).map((t) => (
                                                            <Link
                                                                key={t.symbol}
                                                                href={tickerShow(t.symbol)}
                                                                onClick={(e) => e.stopPropagation()}
                                                            >
                                                                <Badge
                                                                    variant="outline"
                                                                    className="font-mono text-[10px] hover:bg-accent"
                                                                >
                                                                    {t.symbol} {t.wins}/{t.calls}
                                                                </Badge>
                                                            </Link>
                                                        ))}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <ChevronDown
                                                        className={cn(
                                                            'size-4 text-muted-foreground transition-transform',
                                                            expanded === row.rank && 'rotate-180',
                                                        )}
                                                    />
                                                </TableCell>
                                            </TableRow>
                                            {expanded === row.rank && (
                                                <TableRow className="bg-muted/30 hover:bg-muted/30">
                                                    <TableCell colSpan={9} className="p-0">
                                                        <div className="px-6 py-3">
                                                            <p className="mb-2 text-xs font-medium text-muted-foreground">
                                                                Recent graded calls
                                                            </p>
                                                            <div className="grid gap-1.5">
                                                                {(row.recent_calls ?? []).map((call, i) => (
                                                                    <div
                                                                        key={i}
                                                                        className="flex flex-wrap items-center gap-3 text-sm"
                                                                    >
                                                                        <OutcomeBadge outcome={call.outcome} />
                                                                        {call.symbol && (
                                                                            <Link
                                                                                href={tickerShow(call.symbol)}
                                                                                className="w-16 font-mono font-semibold hover:underline"
                                                                            >
                                                                                ${call.symbol}
                                                                            </Link>
                                                                        )}
                                                                        <span className="font-mono text-xs text-muted-foreground">
                                                                            {call.called_at}
                                                                        </span>
                                                                        <span className="font-mono text-xs">
                                                                            peak {pct(call.peak_return)}
                                                                        </span>
                                                                        <span className="font-mono text-xs text-muted-foreground">
                                                                            day-{thresholds.horizon}{' '}
                                                                            {pct(call.day5_return)}
                                                                        </span>
                                                                        <span
                                                                            className="font-mono text-xs text-muted-foreground"
                                                                            title="How much the stock had already moved in the 3 sessions before the call"
                                                                        >
                                                                            run-up {pct(call.runup_3d)}
                                                                        </span>
                                                                        {call.permalink && (
                                                                            <a
                                                                                href={call.permalink}
                                                                                target="_blank"
                                                                                rel="noreferrer"
                                                                                className="text-muted-foreground hover:text-foreground"
                                                                            >
                                                                                <ExternalLink className="size-3.5" />
                                                                            </a>
                                                                        )}
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            )}
                                        </Fragment>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Voices.layout = {
    breadcrumbs: [{ title: 'Voices', href: voices() }],
};
