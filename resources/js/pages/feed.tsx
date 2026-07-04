import { Head, Link, router } from '@inertiajs/react';
import { useEchoPublic } from '@laravel/echo-react';
import { ExternalLink } from 'lucide-react';
import { useCallback, useRef } from 'react';
import { PumpRiskBadge, relativeTime, SentimentBadge } from '@/components/pennyhunt/badges';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { feed } from '@/routes';
import { show as tickerShow } from '@/routes/tickers';

type FeedPost = {
    id: number;
    kind: string;
    title: string | null;
    body: string | null;
    permalink: string | null;
    score: number;
    num_comments: number;
    posted_at: string;
    source: { key: string; name: string };
    author: { username: string; karma: number | null; pump_risk_score: number } | null;
    sentiment: {
        lexicon_score: number | null;
        llm_direction: string | null; // bullish | bearish | neutral
        llm_post_type: string | null;
        llm_conviction: number | null;
        llm_pump_suspicion: number | null;
    } | null;
    tickers: { symbol: string; confidence: number }[];
};

/** LLM verdict > lexicon, mapped to [-1, 1] (mirrors PostSentiment::effectiveScore). */
function effectiveSentiment(sentiment: FeedPost['sentiment']): number | null {
    if (!sentiment) {
        return null;
    }

    if (sentiment.llm_direction !== null) {
        const sign = sentiment.llm_direction === 'bullish' ? 1 : sentiment.llm_direction === 'bearish' ? -1 : 0;

        return sign * (sentiment.llm_conviction ?? 0.5);
    }

    return sentiment.lexicon_score;
}

type Props = {
    posts: {
        data: FeedPost[];
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    sources: { id: number; key: string; name: string; type: string }[];
    filters: { source?: string; symbol?: string; kind?: string; post_type?: string; positions?: string };
};

const POST_TYPES = ['dd', 'technical', 'news', 'hype', 'question', 'other'] as const;

const POST_TYPE_STYLE: Record<string, string> = {
    dd: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
    technical: 'border-sky-500/40 bg-sky-500/10 text-sky-300',
    news: 'border-violet-500/40 bg-violet-500/10 text-violet-300',
    hype: 'border-amber-500/40 bg-amber-500/10 text-amber-400',
    question: 'text-muted-foreground',
    other: 'text-muted-foreground',
};

const ALL = '__all__';

export default function Feed({ posts, sources, filters }: Props) {
    const reloadTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Batch-level ping from ingestion; debounce refetches to at most one per 5s
    useEchoPublic('pennyhunt.feed', '.feed.updated', () => {
        if (reloadTimer.current) {
return;
}

        reloadTimer.current = setTimeout(() => {
            reloadTimer.current = null;
            router.reload({ only: ['posts'] });
        }, 5000);
    });

    const applyFilter = useCallback(
        (key: string, value: string) => {
            router.get(
                feed().url,
                { ...filters, [key]: value === ALL ? undefined : value },
                { preserveState: true, preserveScroll: true },
            );
        },
        [filters],
    );

    return (
        <>
            <Head title="Feed" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="mr-auto text-lg font-semibold">Feed</h1>

                    <Select value={filters.source ?? ALL} onValueChange={(v) => applyFilter('source', v)}>
                        <SelectTrigger className="w-56">
                            <SelectValue placeholder="All sources" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All sources</SelectItem>
                            {sources
                                .filter((s) => s.type === 'reddit')
                                .map((s) => (
                                    <SelectItem key={s.key} value={s.key}>
                                        {s.name}
                                    </SelectItem>
                                ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.kind ?? ALL} onValueChange={(v) => applyFilter('kind', v)}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Posts + comments" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Posts + comments</SelectItem>
                            <SelectItem value="post">Posts</SelectItem>
                            <SelectItem value="comment">Comments</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select value={filters.post_type ?? ALL} onValueChange={(v) => applyFilter('post_type', v)}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="All types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All types</SelectItem>
                            {POST_TYPES.map((t) => (
                                <SelectItem key={t} value={t}>
                                    {t === 'dd' ? 'DD' : t.charAt(0).toUpperCase() + t.slice(1)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Button
                        variant={filters.positions === '1' ? 'secondary' : 'outline'}
                        size="sm"
                        onClick={() => applyFilter('positions', filters.positions === '1' ? ALL : '1')}
                    >
                        My positions
                    </Button>
                </div>

                {posts.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-10 text-center text-sm text-muted-foreground">
                            No posts ingested yet. Configure Reddit API credentials in <code>.env</code> (see the
                            Sources page) — ingestion starts automatically once the scheduler and Horizon are running.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="flex flex-col gap-2">
                        {posts.data.map((post) => (
                            <Card key={post.id} className="py-3">
                                <CardContent className="flex flex-col gap-1.5 px-4">
                                    <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        <Badge variant="outline" className="text-xs">
                                            {post.source.name}
                                        </Badge>
                                        <span>{post.kind}</span>
                                        <span>·</span>
                                        <span>{post.author?.username ?? '[deleted]'}</span>
                                        {post.author?.karma != null && <span>({post.author.karma} karma)</span>}
                                        <span>·</span>
                                        <span>{relativeTime(post.posted_at)}</span>
                                        <span className="ml-auto flex items-center gap-2">
                                            {post.sentiment?.llm_post_type && (
                                                <Badge
                                                    variant="outline"
                                                    className={`font-mono text-[10px] ${POST_TYPE_STYLE[post.sentiment.llm_post_type] ?? 'text-muted-foreground'}`}
                                                    title={
                                                        post.sentiment.llm_conviction !== null
                                                            ? `LLM conviction ${(post.sentiment.llm_conviction * 100).toFixed(0)}%`
                                                            : undefined
                                                    }
                                                >
                                                    {post.sentiment.llm_post_type}
                                                </Badge>
                                            )}
                                            <SentimentBadge value={effectiveSentiment(post.sentiment)} />
                                            <PumpRiskBadge value={post.sentiment?.llm_pump_suspicion ?? post.author?.pump_risk_score} />
                                        </span>
                                    </div>

                                    {post.title && <p className="text-sm font-medium">{post.title}</p>}
                                    {post.body && (
                                        <p className="line-clamp-2 text-sm text-muted-foreground">{post.body}</p>
                                    )}

                                    <div className="flex flex-wrap items-center gap-1.5">
                                        {post.tickers.map((t) => (
                                            <Link key={t.symbol} href={tickerShow(t.symbol)}>
                                                <Badge
                                                    variant="outline"
                                                    className="font-mono text-xs hover:bg-muted"
                                                    title={`extraction confidence ${(t.confidence * 100).toFixed(0)}%`}
                                                >
                                                    ${t.symbol}
                                                </Badge>
                                            </Link>
                                        ))}
                                        <span className="ml-auto flex items-center gap-3 text-xs text-muted-foreground">
                                            <span>▲ {post.score}</span>
                                            <span>{post.num_comments} comments</span>
                                            {post.permalink && (
                                                <a
                                                    href={post.permalink}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="inline-flex items-center gap-1 hover:text-foreground"
                                                >
                                                    <ExternalLink className="size-3" /> source
                                                </a>
                                            )}
                                        </span>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}

                        <div className="flex justify-center gap-2 py-2">
                            {posts.prev_page_url && (
                                <Button variant="outline" size="sm" onClick={() => router.get(posts.prev_page_url!)}>
                                    Newer
                                </Button>
                            )}
                            {posts.next_page_url && (
                                <Button variant="outline" size="sm" onClick={() => router.get(posts.next_page_url!)}>
                                    Older
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

Feed.layout = {
    breadcrumbs: [{ title: 'Feed', href: feed() }],
};
