import { router } from '@inertiajs/react';
import { MessageSquare, Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { search } from '@/routes';
import { show as tickerShow } from '@/routes/tickers';

type Hit = {
    symbol: string;
    name: string | null;
    exchange: string | null;
    last_price: number | null;
    mentions_24h: number;
};

/**
 * Global ticker search (Cmd+K / Ctrl+K). Debounced backend query ranked
 * by 24h social attention; Enter or click navigates to the ticker page,
 * which warms the X/Twitter tape and news in the background.
 */
export function TickerSearch() {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<Hit[]>([]);
    const [active, setActive] = useState(0);
    const [loading, setLoading] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);
    const requestSeq = useRef(0);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOpen((v) => !v);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        if (!open) {
            setQuery('');
            setHits([]);
            setActive(0);
            return;
        }

        // Radix autofocuses the content; steal it for the input.
        setTimeout(() => inputRef.current?.focus(), 50);
    }, [open]);

    useEffect(() => {
        const q = query.trim();

        if (q.length === 0) {
            setHits([]);
            setActive(0);
            return;
        }

        setLoading(true);
        const seq = ++requestSeq.current;

        const timer = setTimeout(() => {
            fetch(`${search().url}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((data: { results: Hit[] }) => {
                    if (seq === requestSeq.current) {
                        setHits(data.results);
                        setActive(0);
                        setLoading(false);
                    }
                })
                .catch(() => setLoading(false));
        }, 180);

        return () => clearTimeout(timer);
    }, [query]);

    const go = useCallback(
        (hit: Hit) => {
            setOpen(false);
            router.visit(tickerShow(hit.symbol).url);
        },
        [],
    );

    const onKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((a) => Math.min(a + 1, hits.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((a) => Math.max(a - 1, 0));
        } else if (e.key === 'Enter' && hits[active]) {
            e.preventDefault();
            go(hits[active]);
        }
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="inline-flex h-8 items-center gap-2 rounded-md border border-border/60 bg-card px-2.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
            >
                <Search className="size-3.5" />
                <span className="hidden sm:inline">Search tickers…</span>
                <kbd className="pointer-events-none hidden rounded border border-border/60 bg-muted px-1.5 font-mono text-[10px] sm:inline">
                    ⌘K
                </kbd>
            </button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="top-[20%] max-w-lg translate-y-0 gap-0 overflow-hidden p-0 [&>button:last-child]:hidden">
                    <DialogTitle className="sr-only">Search tickers</DialogTitle>
                    <div className="flex items-center gap-2 border-b border-border/60 px-3">
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <input
                            ref={inputRef}
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            onKeyDown={onKeyDown}
                            placeholder="Symbol or company name…"
                            className="h-12 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        />
                    </div>
                    <div className="max-h-80 overflow-y-auto p-1">
                        {hits.length === 0 && (
                            <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                                {query.trim().length === 0
                                    ? 'Type a ticker symbol or company name.'
                                    : loading
                                      ? 'Searching…'
                                      : 'No matches.'}
                            </p>
                        )}
                        {hits.map((hit, i) => (
                            <button
                                key={hit.symbol}
                                type="button"
                                onClick={() => go(hit)}
                                onMouseEnter={() => setActive(i)}
                                className={cn(
                                    'flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm',
                                    i === active ? 'bg-accent text-accent-foreground' : 'text-foreground',
                                )}
                            >
                                <span className="font-mono font-semibold">${hit.symbol}</span>
                                <span className="min-w-0 flex-1 truncate text-muted-foreground">{hit.name ?? '—'}</span>
                                {hit.mentions_24h > 0 && (
                                    <span className="inline-flex items-center gap-1 font-mono text-xs text-muted-foreground">
                                        <MessageSquare className="size-3" />
                                        {hit.mentions_24h}
                                    </span>
                                )}
                                {hit.last_price !== null && (
                                    <span className="font-mono text-xs text-muted-foreground">
                                        ${hit.last_price >= 1 ? hit.last_price.toFixed(2) : hit.last_price.toFixed(4)}
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
