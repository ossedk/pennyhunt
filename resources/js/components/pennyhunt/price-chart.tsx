import { useEffect, useState } from 'react';
import {
    Bar,
    CartesianGrid,
    ComposedChart,
    Line,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

export type PriceBar = {
    date: string;
    close: number;
    volume: number;
};

const tooltipStyle = {
    backgroundColor: '#18181b',
    border: '1px solid #27272a',
    borderRadius: 8,
    fontSize: 12,
};

const formatPrice = (value: number) => (value >= 100 ? value.toFixed(0) : value >= 1 ? value.toFixed(2) : value.toFixed(4));

/**
 * Daily close line + volume bars with optional vertical event markers
 * (signal fired, entry, time exit) and horizontal levels (stop).
 */
export function PriceChart({
    bars,
    markers = [],
    levels = [],
    height = 260,
}: {
    bars: PriceBar[];
    markers?: { date: string; label: string; color: string }[];
    levels?: { value: number; label: string; color: string }[];
    height?: number;
}) {
    if (bars.length === 0) {
        return <p className="py-8 text-center text-sm text-muted-foreground">No price data for this window.</p>;
    }

    const data = bars.map((bar) => ({
        ...bar,
        label: new Date(bar.date + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' }),
    }));

    const dateToLabel = new Map(data.map((d) => [d.date, d.label]));

    return (
        <ResponsiveContainer width="100%" height={height}>
            <ComposedChart data={data}>
                <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.06)" />
                <XAxis dataKey="label" tick={{ fontSize: 11, fill: '#71717a' }} minTickGap={24} />
                <YAxis
                    yAxisId="price"
                    domain={['auto', 'auto']}
                    tick={{ fontSize: 11, fill: '#71717a' }}
                    tickFormatter={formatPrice}
                    width={52}
                />
                <YAxis yAxisId="volume" orientation="right" hide />
                <Tooltip
                    contentStyle={tooltipStyle}
                    formatter={(value, name) =>
                        name === 'Volume'
                            ? [Intl.NumberFormat().format(Number(value)), name]
                            : [`$${formatPrice(Number(value))}`, name]
                    }
                />
                <Bar yAxisId="volume" dataKey="volume" fill="#3f3f46" opacity={0.5} name="Volume" />
                <Line
                    yAxisId="price"
                    type="monotone"
                    dataKey="close"
                    stroke="#10b981"
                    strokeWidth={1.5}
                    dot={false}
                    name="Close"
                />
                {markers.map(
                    (marker) =>
                        dateToLabel.has(marker.date) && (
                            <ReferenceLine
                                key={`${marker.date}-${marker.label}`}
                                yAxisId="price"
                                x={dateToLabel.get(marker.date)}
                                stroke={marker.color}
                                strokeDasharray="4 3"
                                label={{ value: marker.label, position: 'top', fontSize: 10, fill: marker.color }}
                            />
                        ),
                )}
                {levels.map((level) => (
                    <ReferenceLine
                        key={level.label}
                        yAxisId="price"
                        y={level.value}
                        stroke={level.color}
                        strokeDasharray="4 3"
                        label={{ value: level.label, position: 'right', fontSize: 10, fill: level.color }}
                    />
                ))}
            </ComposedChart>
        </ResponsiveContainer>
    );
}

type SignalBarsResponse = {
    symbol: string;
    fired_date: string;
    bars: PriceBar[];
    entry_date: string | null;
    entry: number | null;
    stop_level: number | null;
    time_exit_date: string | null;
};

/**
 * Post-signal price chart, fetched lazily when a signal row is expanded.
 * Shows what the validated trade discipline (entry next open, -10% stop,
 * 5-session time exit) would have done.
 */
export function SignalPriceChart({ signalId }: { signalId: number }) {
    const [data, setData] = useState<SignalBarsResponse | null>(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        let cancelled = false;

        fetch(`/signals/${signalId}/bars`, { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : Promise.reject(new Error(String(res.status)))))
            .then((json: SignalBarsResponse) => {
                if (!cancelled) {
setData(json);
}
            })
            .catch(() => {
                if (!cancelled) {
setError(true);
}
            });

        return () => {
            cancelled = true;
        };
    }, [signalId]);

    if (error) {
        return <p className="py-6 text-center text-sm text-muted-foreground">Could not load price data.</p>;
    }

    if (data === null) {
        return <p className="py-6 text-center text-sm text-muted-foreground">Loading price data…</p>;
    }

    // Snap the fired marker to the last session on/before the fire date
    // (signals often fire on weekends when there is no bar).
    const firedBarDate = [...data.bars].reverse().find((bar) => bar.date <= data.fired_date)?.date ?? data.fired_date;

    const markers = [
        { date: firedBarDate, label: 'fired', color: '#f59e0b' },
        ...(data.entry_date ? [{ date: data.entry_date, label: 'entry', color: '#10b981' }] : []),
        ...(data.time_exit_date ? [{ date: data.time_exit_date, label: '5d exit', color: '#71717a' }] : []),
    ];

    const levels = data.stop_level !== null ? [{ value: data.stop_level, label: '-10% stop', color: '#ef4444' }] : [];

    return <PriceChart bars={data.bars} markers={markers} levels={levels} height={240} />;
}
