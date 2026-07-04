import { Head } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { relativeTime } from '@/components/pennyhunt/badges';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { sources } from '@/routes';

type SourceRow = {
    id: number;
    key: string;
    type: string;
    name: string;
    enabled: boolean;
    poll_interval_seconds: number;
    last_polled_at: string | null;
    last_ok_at: string | null;
    last_error: string | null;
    consecutive_failures: number;
    total_posts: number;
    latest_post_at: string | null;
};

type Props = {
    sources: SourceRow[];
    redditConfigured: boolean;
};

export default function Sources({ sources: sourceRows, redditConfigured }: Props) {
    return (
        <>
            <Head title="Sources" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-lg font-semibold">Sources</h1>

                {!redditConfigured && (
                    <Alert className="border-amber-500/40 bg-amber-500/5">
                        <AlertTriangle className="size-4 text-amber-400" />
                        <AlertTitle>Reddit ingestion not configured</AlertTitle>
                        <AlertDescription>
                            Set <code>APIFY_KEY</code> in <code>.env</code> (primary path, one batched scrape per 15
                            min), or create a free "script" app at reddit.com/prefs/apps and set{' '}
                            <code>REDDIT_CLIENT_ID</code> / <code>REDDIT_CLIENT_SECRET</code> as the fallback. Until
                            then only the keyless aggregators are ingesting.
                        </AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Source</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Cadence</TableHead>
                                    <TableHead>Last poll</TableHead>
                                    <TableHead className="text-right">Rows archived</TableHead>
                                    <TableHead>Latest item</TableHead>
                                    <TableHead>Error</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sourceRows.map((source) => {
                                    const status = !source.enabled
                                        ? 'disabled'
                                        : source.consecutive_failures > 0
                                          ? 'failing'
                                          : source.last_ok_at
                                            ? 'ok'
                                            : 'pending';

                                    return (
                                        <TableRow key={source.id}>
                                            <TableCell>
                                                <span className="font-medium">{source.name}</span>
                                                <span className="ml-2 text-xs text-muted-foreground">{source.type}</span>
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className={cn(
                                                        'text-xs',
                                                        status === 'ok' && 'border-emerald-500/40 bg-emerald-500/10 text-emerald-400',
                                                        status === 'failing' && 'border-red-500/40 bg-red-500/10 text-red-400',
                                                        (status === 'pending' || status === 'disabled') && 'text-muted-foreground',
                                                    )}
                                                >
                                                    {status}
                                                    {source.consecutive_failures > 1 && ` ×${source.consecutive_failures}`}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="font-mono text-xs text-muted-foreground">
                                                {source.poll_interval_seconds >= 60
                                                    ? `${Math.round(source.poll_interval_seconds / 60)}m`
                                                    : `${source.poll_interval_seconds}s`}
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                {source.last_polled_at ? relativeTime(source.last_polled_at) : 'never'}
                                            </TableCell>
                                            <TableCell className="text-right font-mono">{source.total_posts}</TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                {source.latest_post_at ? relativeTime(source.latest_post_at) : '—'}
                                            </TableCell>
                                            <TableCell className="max-w-64 truncate text-xs text-red-400/80" title={source.last_error ?? ''}>
                                                {source.last_error ?? ''}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Sources.layout = {
    breadcrumbs: [{ title: 'Sources', href: sources() }],
};
