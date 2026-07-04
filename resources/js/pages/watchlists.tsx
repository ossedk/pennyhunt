import { Head, Link, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { SentimentBadge, ZScoreBadge } from '@/components/pennyhunt/badges';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { watchlists } from '@/routes';
import { show as tickerShow } from '@/routes/tickers';
import { destroy as watchlistDestroy, store as watchlistStore } from '@/routes/watchlists';

type WatchedTicker = {
    id: number;
    symbol: string;
    name: string | null;
    exchange: string | null;
    last_price: number | null;
    latest_metric: {
        mention_count: number;
        unique_authors: number;
        weighted_sentiment: number | null;
        zscore_mentions: number | null;
        bucket_start: string;
    } | null;
};

type Props = {
    watchlist: { id: number; name: string };
    tickers: WatchedTicker[];
};

export default function Watchlists({ tickers }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm({ symbol: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(watchlistStore().url, { onSuccess: () => reset() });
    };

    return (
        <>
            <Head title="Watchlists" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-lg font-semibold">Watchlist</h1>
                    <form onSubmit={submit} className="flex items-start gap-2">
                        <div>
                            <Input
                                value={data.symbol}
                                onChange={(event) => setData('symbol', event.target.value.toUpperCase())}
                                placeholder="Add symbol (e.g. GME)"
                                className="w-48 font-mono uppercase"
                                maxLength={10}
                            />
                            <InputError message={errors.symbol} className="mt-1" />
                        </div>
                        <Button type="submit" disabled={processing || data.symbol.length === 0}>
                            Watch
                        </Button>
                    </form>
                </div>

                <Card>
                    <CardContent>
                        {tickers.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                No tickers watched yet. Add a symbol above to keep it pinned with live mention and
                                sentiment metrics.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Ticker</TableHead>
                                        <TableHead className="text-right">Price</TableHead>
                                        <TableHead className="text-right">Mentions / h</TableHead>
                                        <TableHead>Accel</TableHead>
                                        <TableHead>Sentiment</TableHead>
                                        <TableHead />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {tickers.map((ticker) => (
                                        <TableRow key={ticker.id}>
                                            <TableCell>
                                                <Link
                                                    href={tickerShow(ticker.symbol)}
                                                    className="font-mono font-semibold hover:underline"
                                                >
                                                    {ticker.symbol}
                                                </Link>
                                                <span className="ml-2 text-xs text-muted-foreground">{ticker.name}</span>
                                            </TableCell>
                                            <TableCell className="text-right font-mono">
                                                {ticker.last_price !== null ? `$${ticker.last_price}` : '—'}
                                            </TableCell>
                                            <TableCell className="text-right font-mono">
                                                {ticker.latest_metric?.mention_count ?? 0}
                                            </TableCell>
                                            <TableCell>
                                                <ZScoreBadge value={ticker.latest_metric?.zscore_mentions} />
                                            </TableCell>
                                            <TableCell>
                                                <SentimentBadge value={ticker.latest_metric?.weighted_sentiment} />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        router.delete(watchlistDestroy(ticker.id).url, {
                                                            preserveScroll: true,
                                                        })
                                                    }
                                                >
                                                    <Trash2 className="size-4 text-muted-foreground" />
                                                </Button>
                                            </TableCell>
                                        </TableRow>
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

Watchlists.layout = {
    breadcrumbs: [{ title: 'Watchlists', href: watchlists() }],
};
