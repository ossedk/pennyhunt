import { Activity, Pause } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { InfoTip } from '@/components/pennyhunt/info-tip';
import { cn } from '@/lib/utils';

type LiveAssessment = {
    market: string;
    verdict:
        | 'enter_window'
        | 'wait'
        | 'stand_aside'
        | 'neutral'
        | 'hold'
        | 'caution'
        | 'exit'
        | 'exit_today'
        | 'market_closed';
    headline: string;
    reasons: string[];
    metrics: {
        price: number | null;
        change_pct: number | null;
        session: string;
        vs_fire_close: number | null;
        vwap_dist: number | null;
        or_return: number | null;
        or_vol_share: number | null;
        gap_faded: boolean | null;
        mentions_last_hour: number;
        fire_day_hourly_pace: number | null;
        crowd_ratio: number | null;
        position: {
            book: string;
            status: string;
            entry_price: number | null;
            stop_price: number | null;
            time_exit_date: string | null;
        } | null;
    } | null;
    as_of: string;
};

const VERDICT_STYLES: Record<LiveAssessment['verdict'], { label: string; className: string }> = {
    enter_window: { label: 'ENTRY WINDOW', className: 'bg-emerald-500/15 text-emerald-500 border-emerald-500/40' },
    hold: { label: 'HOLD', className: 'bg-emerald-500/15 text-emerald-500 border-emerald-500/40' },
    neutral: { label: 'NO READ', className: 'bg-muted text-muted-foreground border-border' },
    wait: { label: 'WAIT', className: 'bg-amber-500/15 text-amber-500 border-amber-500/40' },
    caution: { label: 'CAUTION', className: 'bg-amber-500/15 text-amber-500 border-amber-500/40' },
    stand_aside: { label: 'STAND ASIDE', className: 'bg-red-500/15 text-red-500 border-red-500/40' },
    exit: { label: 'EXIT', className: 'bg-red-500/15 text-red-500 border-red-500/40' },
    exit_today: { label: 'EXIT TODAY', className: 'bg-red-500/15 text-red-500 border-red-500/40' },
    market_closed: { label: 'MARKET CLOSED', className: 'bg-muted text-muted-foreground border-border' },
};

const pct = (v: number | null | undefined, digits = 1) =>
    v === null || v === undefined ? '—' : `${v >= 0 ? '+' : ''}${(v * 100).toFixed(digits)}%`;

/**
 * Polls /signals/{id}/live every 75s while the market is in any session,
 * rendering the rule-based enter/hold/exit verdict with its reasons.
 */
export function LiveDeskCard({ url }: { url: string }) {
    const [data, setData] = useState<LiveAssessment | null>(null);

    useEffect(() => {
        let cancelled = false;
        let timer: ReturnType<typeof setTimeout> | null = null;

        const load = async () => {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });

            if (!response.ok || cancelled) {
                return;
            }

            const next: LiveAssessment = await response.json();
            setData(next);

            // Poll fast in-session; slow when closed.
            timer = setTimeout(() => void load(), next.verdict === 'market_closed' ? 10 * 60_000 : 75_000);
        };

        void load();

        return () => {
            cancelled = true;

            if (timer) {
                clearTimeout(timer);
            }
        };
    }, [url]);

    const verdict = data ? VERDICT_STYLES[data.verdict] : null;
    const m = data?.metrics;

    return (
        <Card className={cn('border', data?.verdict === 'exit' || data?.verdict === 'exit_today' ? 'border-red-500/40' : '')}>
            <CardHeader>
                <CardTitle className="flex items-center gap-1.5 text-sm">
                    {data?.verdict === 'market_closed' ? (
                        <Pause className="size-4 text-muted-foreground" />
                    ) : (
                        <Activity className="size-4 animate-pulse text-emerald-400" />
                    )}
                    Live desk
                    <InfoTip>
                        Rule-based read of the current session, refreshed ~every minute: chasing veto (fire close
                        +15%), session VWAP hold/fade, opening volume, crowd pace vs fire day, stop/time discipline.
                        Every rule is one the backtests validated — no vibes.
                    </InfoTip>
                    {verdict && (
                        <Badge variant="outline" className={cn('ml-auto font-mono text-[11px]', verdict.className)}>
                            {verdict.label}
                        </Badge>
                    )}
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                {!data ? (
                    <p className="py-3 text-center text-sm text-muted-foreground">Reading the tape…</p>
                ) : (
                    <>
                        <p className="text-sm font-medium">{data.headline}</p>
                        {data.reasons.length > 0 && (
                            <ul className="flex flex-col gap-1">
                                {data.reasons.map((reason, i) => (
                                    <li key={i} className="flex items-start gap-2 text-xs text-muted-foreground">
                                        <span className="mt-1 inline-block size-1 shrink-0 rounded-full bg-muted-foreground" />
                                        {reason}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {m && (
                            <div className="mt-1 grid grid-cols-2 gap-x-4 gap-y-1 border-t border-border/60 pt-2 font-mono text-xs sm:grid-cols-3">
                                <span>
                                    px <span className="text-foreground">{m.price ?? '—'}</span>{' '}
                                    <span className={m.change_pct !== null && m.change_pct >= 0 ? 'text-emerald-400' : 'text-rose-400'}>
                                        {pct(m.change_pct)}
                                    </span>
                                </span>
                                <span>
                                    vs fire <span className="text-foreground">{pct(m.vs_fire_close)}</span>
                                </span>
                                <span>
                                    VWAP <span className="text-foreground">{pct(m.vwap_dist)}</span>
                                </span>
                                <span>
                                    OR vol <span className="text-foreground">{m.or_vol_share !== null ? `${(m.or_vol_share * 100).toFixed(0)}%` : '—'}</span>
                                </span>
                                <span>
                                    crowd{' '}
                                    <span className="text-foreground">
                                        {m.crowd_ratio !== null ? `${(m.crowd_ratio * 100).toFixed(0)}%` : '—'}
                                    </span>{' '}
                                    ({m.mentions_last_hour}/h)
                                </span>
                                {m.position && (
                                    <span>
                                        {m.position.book} <span className="text-foreground">{m.position.status}</span>
                                    </span>
                                )}
                            </div>
                        )}
                        <p className="text-[10px] text-muted-foreground">
                            {data.market.replace('_', ' ')} · updated {new Date(data.as_of).toLocaleTimeString()} ·
                            15-min delayed tape · advisory only, authoritative exits settle on daily bars
                        </p>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
