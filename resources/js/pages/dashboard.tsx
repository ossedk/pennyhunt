import { Head, Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, ExternalLink, Newspaper, Sparkles } from 'lucide-react';
import type { MarketStatus } from '@/components/pennyhunt/badges';
import { MarketStatusBadge, relativeTime, SentimentBadge } from '@/components/pennyhunt/badges';
import { InfoTip } from '@/components/pennyhunt/info-tip';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { dashboard, signals } from '@/routes';
import { show as signalShow } from '@/routes/signals';
import { show as tickerShow } from '@/routes/tickers';

type Brief = {
    headline: string;
    body: string[];
    watch: { symbol: string; reason: string }[];
    risks: string[];
    generated_at: string;
} | null;

type Mover = {
    symbol: string;
    name: string | null;
    mentions: number;
    day_return: number;
    close: number;
    volume: number | null;
};

type Loud = {
    symbol: string;
    name: string | null;
    last_price: number | null;
    mentions: number;
    authors: number;
    sentiment: number | null;
};

type HypedPost = {
    id: number;
    title: string | null;
    body: string;
    permalink: string | null;
    score: number;
    posted_at: string;
    source: { key: string; name: string };
    author: { username: string; pump_risk_score: number | null } | null;
    symbols: string[];
    sentiment: {
        lexicon_score: number | null;
        llm_direction: string | null;
        llm_post_type: string | null;
        llm_pump_suspicion: number | null;
    } | null;
};

type Position = {
    id: number;
    signal_id: number;
    symbol: string;
    status: string;
    unrealized_return: number | null;
    holding_day: number | null;
};

type RecentSignal = {
    id: number;
    symbol: string;
    confidence: number | null;
    fired_at: string;
};

type NewsItem = {
    id: number;
    symbol: string;
    publisher: string | null;
    title: string;
    article_url: string;
    image_url: string | null;
    published_at: string;
    mentions_24h: number;
};

type Props = {
    brief: Brief;
    marketStatus: MarketStatus | null;
    regime: {
        vix: number | null;
        market_ret_5d: number | null;
        btc_ret_5d: number | null;
        site_mention_z: number | null;
    };
    movers: Mover[];
    loudest: Loud[];
    hypedPosts: HypedPost[];
    positions: Position[];
    recentSignals: RecentSignal[];
    news: NewsItem[];
};

const pct = (v: number | null | undefined, digits = 1) =>
    v === null || v === undefined ? '—' : `${v >= 0 ? '+' : ''}${(v * 100).toFixed(digits)}%`;

const returnColor = (v: number | null | undefined) =>
    v === null || v === undefined ? 'text-muted-foreground' : v > 0 ? 'text-emerald-400' : v < 0 ? 'text-rose-400' : 'text-muted-foreground';

const price = (v: number | null | undefined) =>
    v === null || v === undefined ? '—' : `$${v >= 1 ? v.toFixed(2) : v.toFixed(4)}`;

export default function Dashboard({
    brief,
    marketStatus,
    regime,
    movers,
    loudest,
    hypedPosts,
    positions,
    recentSignals,
    news,
}: Props) {
    return (
        <>
            <Head title="Desk" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-3">
                        <h1 className="text-lg font-semibold">The Desk</h1>
                        <MarketStatusBadge market={marketStatus} />
                    </div>
                    <RegimeStrip regime={regime} />
                </div>

                <BriefHero brief={brief} />

                <div className="grid gap-4 xl:grid-cols-3">
                    <div className="flex flex-col gap-4 xl:col-span-2">
                        <div className="grid gap-4 md:grid-cols-2">
                            <MoversCard movers={movers} />
                            <LoudestCard loudest={loudest} />
                        </div>
                        <HypedPostsCard posts={hypedPosts} />
                    </div>

                    <div className="flex flex-col gap-4">
                        <PositionsCard positions={positions} signals={recentSignals} />
                        <NewsCard news={news} />
                    </div>
                </div>
            </div>
        </>
    );
}

/**
 * The LLM-written morning brief. Watch items are symbol-bound (validated
 * server-side against the context universe) and link into ticker pages.
 */
function BriefHero({ brief }: { brief: Brief }) {
    if (!brief) {
        return (
            <Card className="border-dashed">
                <CardContent className="flex items-center gap-3 py-6 text-sm text-muted-foreground">
                    <Sparkles className="size-4 animate-pulse" />
                    Writing the market brief… it lands here within a couple of minutes (refresh to check).
                </CardContent>
            </Card>
        );
    }

    return (
        <Card className="border-primary/20 bg-gradient-to-br from-primary/5 to-transparent">
            <CardHeader className="pb-2">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <CardTitle className="flex items-center gap-2 text-base leading-snug">
                        <Sparkles className="size-4 shrink-0 text-primary" />
                        {brief.headline}
                    </CardTitle>
                    <span className="text-xs text-muted-foreground">brief written {relativeTime(brief.generated_at)}</span>
                </div>
            </CardHeader>
            <CardContent className="space-y-3">
                <div className="max-w-4xl space-y-2 text-sm leading-relaxed text-foreground/90">
                    {brief.body.map((paragraph, i) => (
                        <p key={i}>{paragraph}</p>
                    ))}
                </div>

                {brief.watch.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {brief.watch.map((w) => (
                            <Link
                                key={w.symbol}
                                href={tickerShow(w.symbol)}
                                className="group flex items-center gap-2 rounded-md border border-border/60 bg-card px-3 py-1.5 text-xs transition-colors hover:border-primary/40 hover:bg-accent"
                            >
                                <span className="font-mono font-semibold text-primary">${w.symbol}</span>
                                <span className="max-w-72 truncate text-muted-foreground group-hover:text-accent-foreground">
                                    {w.reason}
                                </span>
                            </Link>
                        ))}
                    </div>
                )}

                {brief.risks.length > 0 && (
                    <div className="flex flex-wrap gap-x-4 gap-y-1">
                        {brief.risks.map((risk, i) => (
                            <span key={i} className="text-xs text-amber-500/90">
                                ⚠ {risk}
                            </span>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function RegimeStrip({ regime }: { regime: Props['regime'] }) {
    const vixTone = regime.vix === null ? undefined : regime.vix < 20 ? 'text-emerald-400' : regime.vix < 28 ? 'text-amber-400' : 'text-rose-400';

    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md border border-border/60 px-3 py-1.5 text-xs">
            <Stat label="VIX" value={regime.vix?.toFixed(1) ?? '—'} accent={vixTone} />
            <Stat label="S&P 5d" value={pct(regime.market_ret_5d)} accent={returnColor(regime.market_ret_5d)} />
            <Stat label="BTC 5d" value={pct(regime.btc_ret_5d)} accent={returnColor(regime.btc_ret_5d)} />
            <Stat
                label="Buzz"
                value={regime.site_mention_z !== null ? `${regime.site_mention_z >= 0 ? '+' : ''}${regime.site_mention_z.toFixed(1)}σ` : '—'}
            />
        </div>
    );
}

function Stat({ label, value, accent }: { label: string; value: string; accent?: string }) {
    return (
        <span className="flex items-baseline gap-1">
            <span className="text-muted-foreground">{label}</span>
            <span className={cn('font-mono font-medium', accent)}>{value}</span>
        </span>
    );
}

function MoversCard({ movers }: { movers: Mover[] }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="flex items-center gap-1.5 text-sm">
                    Tape moves
                    <InfoTip>
                        Biggest last-session price moves among tickers with social chatter in the past 48h — what
                        actually moved, not just what was loud.
                    </InfoTip>
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-1">
                {movers.length === 0 && <Empty message="No bar data yet for chattered tickers." />}
                {movers.map((m) => (
                    <Link
                        key={m.symbol}
                        href={tickerShow(m.symbol)}
                        className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors hover:bg-accent"
                    >
                        {m.day_return >= 0 ? (
                            <ArrowUpRight className="size-3.5 shrink-0 text-emerald-400" />
                        ) : (
                            <ArrowDownRight className="size-3.5 shrink-0 text-rose-400" />
                        )}
                        <span className="font-mono font-semibold">${m.symbol}</span>
                        <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">{m.name ?? ''}</span>
                        <span className="font-mono text-xs text-muted-foreground">{price(m.close)}</span>
                        <span className={cn('w-16 text-right font-mono text-sm font-medium', returnColor(m.day_return))}>
                            {pct(m.day_return)}
                        </span>
                    </Link>
                ))}
            </CardContent>
        </Card>
    );
}

function LoudestCard({ loudest }: { loudest: Loud[] }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="flex items-center gap-1.5 text-sm">
                    Crowd volume
                    <InfoTip>
                        Most-mentioned tickers on the forums in the last 24h (Reddit only — X is display-only), with
                        unique-author breadth and average sentiment.
                    </InfoTip>
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-1">
                {loudest.length === 0 && <Empty message="No mentions in the last 24h." />}
                {loudest.map((l) => (
                    <Link
                        key={l.symbol}
                        href={tickerShow(l.symbol)}
                        className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors hover:bg-accent"
                    >
                        <span className="font-mono font-semibold">${l.symbol}</span>
                        <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">{l.name ?? ''}</span>
                        <SentimentBadge value={l.sentiment} />
                        <span className="w-20 text-right font-mono text-xs text-muted-foreground">
                            {l.mentions}× · {l.authors}a
                        </span>
                    </Link>
                ))}
            </CardContent>
        </Card>
    );
}

function HypedPostsCard({ posts }: { posts: HypedPost[] }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="flex items-center gap-1.5 text-sm">
                    Loudest posts (24h)
                    <InfoTip>
                        Highest-engagement ticker posts of the last day, with LLM labels. High pump suspicion means the
                        classifier thinks it reads like coordinated promotion.
                    </InfoTip>
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
                {posts.length === 0 && <Empty message="No posts in the last 24h." />}
                {posts.map((post) => (
                    <div key={post.id} className="rounded-md border border-border/50 px-3 py-2">
                        <div className="flex items-start justify-between gap-2">
                            <p className="min-w-0 text-sm font-medium leading-snug">
                                {post.permalink ? (
                                    <a href={post.permalink} target="_blank" rel="noreferrer" className="hover:underline">
                                        {post.title ?? post.body}
                                    </a>
                                ) : (
                                    (post.title ?? post.body)
                                )}
                            </p>
                            <span className="shrink-0 font-mono text-xs text-muted-foreground">▲ {post.score}</span>
                        </div>
                        <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            {post.symbols.map((s) => (
                                <Link key={s} href={tickerShow(s)} className="font-mono font-semibold text-primary hover:underline">
                                    ${s}
                                </Link>
                            ))}
                            <span>{post.source.name}</span>
                            {post.author && <span>u/{post.author.username}</span>}
                            <span>{relativeTime(post.posted_at)}</span>
                            {post.sentiment?.llm_post_type && (
                                <Badge variant="outline" className="h-5 px-1.5 text-[10px] uppercase">
                                    {post.sentiment.llm_post_type}
                                </Badge>
                            )}
                            {post.sentiment?.llm_direction && (
                                <Badge
                                    variant="outline"
                                    className={cn(
                                        'h-5 px-1.5 text-[10px] uppercase',
                                        post.sentiment.llm_direction === 'bullish' && 'text-emerald-400',
                                        post.sentiment.llm_direction === 'bearish' && 'text-rose-400',
                                    )}
                                >
                                    {post.sentiment.llm_direction}
                                </Badge>
                            )}
                            {(post.sentiment?.llm_pump_suspicion ?? 0) >= 0.6 && (
                                <Badge variant="outline" className="h-5 border-amber-500/40 px-1.5 text-[10px] uppercase text-amber-400">
                                    pump?
                                </Badge>
                            )}
                        </div>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

function PositionsCard({ positions, signals: recent }: { positions: Position[]; signals: RecentSignal[] }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="flex items-center justify-between text-sm">
                    <span>Open risk & signals</span>
                    <Link href={signals()} className="text-xs font-normal text-muted-foreground hover:underline">
                        blotter →
                    </Link>
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                <div className="space-y-1">
                    {positions.length === 0 && <Empty message="No open paper positions." />}
                    {positions.map((p) => (
                        <Link
                            key={p.id}
                            href={signalShow(p.signal_id)}
                            className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors hover:bg-accent"
                        >
                            <span className="font-mono font-semibold">${p.symbol}</span>
                            <span className="flex-1 text-xs text-muted-foreground">
                                {p.status === 'open' ? `day ${p.holding_day ?? '—'}/5` : 'awaiting entry'}
                            </span>
                            <span className={cn('font-mono text-sm', returnColor(p.unrealized_return))}>
                                {pct(p.unrealized_return)}
                            </span>
                        </Link>
                    ))}
                </div>

                {recent.length > 0 && (
                    <div className="border-t border-border/50 pt-2">
                        <p className="mb-1 px-2 text-xs font-medium text-muted-foreground">Latest signals</p>
                        {recent.map((s) => (
                            <Link
                                key={s.id}
                                href={signalShow(s.id)}
                                className="flex items-center gap-2 rounded-md px-2 py-1 text-sm transition-colors hover:bg-accent"
                            >
                                <span className="font-mono font-semibold">${s.symbol}</span>
                                <span className="flex-1 text-xs text-muted-foreground">{relativeTime(s.fired_at)}</span>
                                <span className="font-mono text-xs text-muted-foreground">
                                    {s.confidence !== null ? `p=${s.confidence.toFixed(3)}` : '—'}
                                </span>
                            </Link>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function NewsCard({ news }: { news: NewsItem[] }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="flex items-center gap-1.5 text-sm">
                    <Newspaper className="size-3.5" />
                    Hyped-name news
                    <InfoTip>
                        Fresh wire stories for the tickers with the most forum mentions in the last 24h — the news
                        behind the noise.
                    </InfoTip>
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
                {news.length === 0 && <Empty message="No fresh news for trending tickers yet." />}
                {news.map((n) => (
                    <a
                        key={n.id}
                        href={n.article_url}
                        target="_blank"
                        rel="noreferrer"
                        className="group block rounded-md border border-border/50 px-3 py-2 transition-colors hover:bg-accent"
                    >
                        <p className="text-sm font-medium leading-snug group-hover:underline">{n.title}</p>
                        <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            <Link
                                href={tickerShow(n.symbol)}
                                onClick={(e) => e.stopPropagation()}
                                className="font-mono font-semibold text-primary hover:underline"
                            >
                                ${n.symbol}
                            </Link>
                            <span>{n.publisher ?? 'wire'}</span>
                            <span>{relativeTime(n.published_at)}</span>
                            <span className="ml-auto inline-flex items-center gap-1">
                                {n.mentions_24h}× mentions
                                <ExternalLink className="size-3" />
                            </span>
                        </div>
                    </a>
                ))}
            </CardContent>
        </Card>
    );
}

function Empty({ message }: { message: string }) {
    return <p className="px-2 py-4 text-center text-xs text-muted-foreground">{message}</p>;
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Desk', href: dashboard() }],
};
