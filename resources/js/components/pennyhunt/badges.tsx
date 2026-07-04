import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/**
 * Sentiment in [-1, 1] -> green / red / neutral badge.
 */
export function SentimentBadge({ value }: { value: number | null | undefined }) {
    if (value === null || value === undefined) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    const label = value > 0.1 ? 'bullish' : value < -0.1 ? 'bearish' : 'neutral';

    return (
        <Badge
            variant="outline"
            className={cn(
                'font-mono text-xs',
                label === 'bullish' && 'border-emerald-500/40 bg-emerald-500/10 text-emerald-400',
                label === 'bearish' && 'border-red-500/40 bg-red-500/10 text-red-400',
                label === 'neutral' && 'text-muted-foreground',
            )}
        >
            {value > 0 ? '+' : ''}
            {value.toFixed(2)}
        </Badge>
    );
}

/**
 * Z-score badge: the "compared to what" for mention counts.
 */
export function ZScoreBadge({ value }: { value: number | null | undefined }) {
    if (value === null || value === undefined) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    return (
        <Badge
            variant="outline"
            className={cn(
                'font-mono text-xs',
                value >= 2 && 'border-emerald-500/40 bg-emerald-500/15 text-emerald-300',
                value >= 1 && value < 2 && 'border-emerald-500/30 bg-emerald-500/5 text-emerald-400/80',
                value < 1 && 'text-muted-foreground',
            )}
        >
            {value >= 0 ? '+' : ''}
            {value.toFixed(1)}σ
        </Badge>
    );
}

/**
 * Pump risk (0..1). Always shown next to bullish sentiment — the UI must never
 * present a likely pump as a clean buy signal.
 */
export function PumpRiskBadge({ value }: { value: number | null | undefined }) {
    if (value === null || value === undefined || value < 0.3) {
        return null;
    }

    return (
        <Badge variant="outline" className="border-amber-500/40 bg-amber-500/10 font-mono text-xs text-amber-400">
            pump risk {(value * 100).toFixed(0)}%
        </Badge>
    );
}

/**
 * Signal tier vs. the active model's validated trade threshold:
 * TRADE (>= calibrated tier), WATCH (scored but below), or unscored.
 */
export function TierBadge({
    confidence,
    threshold,
}: {
    confidence: number | null | undefined;
    threshold: number | null | undefined;
}) {
    if (confidence === null || confidence === undefined) {
        return (
            <Badge variant="outline" className="font-mono text-[10px] text-muted-foreground">
                unscored
            </Badge>
        );
    }

    const isTrade = threshold !== null && threshold !== undefined && confidence >= threshold;

    return (
        <Badge
            variant="outline"
            className={cn(
                'font-mono text-[10px]',
                isTrade
                    ? 'border-emerald-500/50 bg-emerald-500/15 text-emerald-300'
                    : 'border-amber-500/30 bg-amber-500/5 text-amber-400/90',
            )}
        >
            {isTrade ? 'TRADE' : 'WATCH'}
        </Badge>
    );
}

/**
 * Trade lifecycle status chip for the blotter and cockpit.
 */
export function TradeStatusBadge({ status }: { status: string }) {
    const styles: Record<string, string> = {
        pending_entry: 'border-sky-500/40 bg-sky-500/10 text-sky-300',
        open: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
        closed: 'border-border text-muted-foreground',
        cancelled: 'border-border text-muted-foreground line-through',
    };

    const labels: Record<string, string> = {
        pending_entry: 'awaiting entry',
        open: 'open',
        closed: 'closed',
        cancelled: 'void',
    };

    return (
        <Badge variant="outline" className={cn('font-mono text-[10px]', styles[status] ?? 'text-muted-foreground')}>
            {labels[status] ?? status}
        </Badge>
    );
}

/**
 * US market session state ("Market open", "Pre-market", "After hours",
 * "Market closed") so prices and P&L are read with the right context.
 */
export type MarketStatus = {
    status: 'open' | 'early_hours' | 'after_hours' | 'closed';
    as_of: string;
    source: string;
};

export function MarketStatusBadge({ market }: { market: MarketStatus | null | undefined }) {
    if (!market) {
        return null;
    }

    const meta: Record<MarketStatus['status'], { label: string; dot: string; text: string }> = {
        open: { label: 'Market open', dot: 'bg-emerald-500', text: 'text-emerald-400' },
        early_hours: { label: 'Pre-market', dot: 'bg-sky-400', text: 'text-sky-300' },
        after_hours: { label: 'After hours', dot: 'bg-amber-400', text: 'text-amber-300' },
        closed: { label: 'Market closed', dot: 'bg-zinc-500', text: 'text-muted-foreground' },
    };

    const { label, dot, text } = meta[market.status] ?? meta.closed;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-md border border-border/60 px-2 py-0.5 font-mono text-xs',
                text,
            )}
        >
            <span className={cn('size-1.5 rounded-full', dot, market.status === 'open' && 'animate-pulse')} />
            {label}
        </span>
    );
}

/**
 * Data freshness chip ("Reddit: 40s ago") — latency honesty.
 */
export function FreshnessChip({ label, at }: { label: string; at: string | null | undefined }) {
    return (
        <span className="inline-flex items-center gap-1 rounded-md border border-border/60 px-2 py-0.5 text-xs text-muted-foreground">
            <span
                className={cn(
                    'size-1.5 rounded-full',
                    at ? 'bg-emerald-500' : 'bg-zinc-600',
                )}
            />
            {label}: {at ? relativeTime(at) : 'no data'}
        </span>
    );
}

export function relativeTime(iso: string): string {
    const seconds = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));

    if (seconds < 60) {
return `${seconds}s ago`;
}

    if (seconds < 3600) {
return `${Math.floor(seconds / 60)}m ago`;
}

    if (seconds < 86400) {
return `${Math.floor(seconds / 3600)}h ago`;
}

    return `${Math.floor(seconds / 86400)}d ago`;
}
