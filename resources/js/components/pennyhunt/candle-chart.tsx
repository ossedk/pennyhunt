import {
    CandlestickSeries,
    ColorType,
    createChart,
    createSeriesMarkers,
    CrosshairMode,
    HistogramSeries,
    LineStyle
    
    
    
    
    
} from 'lightweight-charts';
import type {IChartApi, ISeriesApi, SeriesMarker, Time, UTCTimestamp} from 'lightweight-charts';
import { useEffect, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export type OhlcBar = {
    date: string; // Y-m-d
    open: number;
    high: number;
    low: number;
    close: number;
    volume: number;
};

export type ChartMarker = {
    date: string;
    label: string;
    color: string;
};

export type ChartLevel = {
    value: number;
    label: string;
    color: string;
};

const RANGES = [
    { key: '1M', days: 31 },
    { key: '3M', days: 93 },
    { key: '6M', days: 186 },
    { key: '1Y', days: 366 },
    { key: 'All', days: Infinity },
] as const;

type RangeKey = (typeof RANGES)[number]['key'];

const UP = '#10b981';
const DOWN = '#f43f5e';

const toTime = (date: string): UTCTimestamp => (Date.parse(date + 'T00:00:00Z') / 1000) as UTCTimestamp;

function priceDecimals(price: number): number {
    if (price >= 100) {
return 2;
}

    if (price >= 1) {
return 2;
}

    if (price >= 0.1) {
return 3;
}

    return 4;
}

const fmtPrice = (v: number) => v.toFixed(priceDecimals(v));

const fmtVolume = (v: number) => {
    if (v >= 1e9) {
return (v / 1e9).toFixed(2) + 'B';
}

    if (v >= 1e6) {
return (v / 1e6).toFixed(1) + 'M';
}

    if (v >= 1e3) {
return (v / 1e3).toFixed(0) + 'K';
}

    return String(Math.round(v));
};

type LegendState = {
    date: string;
    open: number;
    high: number;
    low: number;
    close: number;
    volume: number;
    change: number | null; // vs previous close
};

/**
 * Institutional-grade daily chart: candlesticks + volume histogram
 * (TradingView Lightweight Charts), crosshair OHLC legend, range switcher
 * and event markers (fired signals). Dark-theme tuned for penny-stock price
 * scales (dynamic decimal precision down to $0.0001).
 */
export function CandleChart({
    bars,
    markers = [],
    levels = [],
    height = 380,
    defaultRange = '6M',
}: {
    bars: OhlcBar[];
    markers?: ChartMarker[];
    levels?: ChartLevel[];
    height?: number;
    defaultRange?: RangeKey;
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const chartRef = useRef<IChartApi | null>(null);
    const candleRef = useRef<ISeriesApi<'Candlestick'> | null>(null);
    const [range, setRange] = useState<RangeKey>(defaultRange);
    const [legend, setLegend] = useState<LegendState | null>(null);

    const barByTime = useMemo(() => {
        const map = new Map<number, { bar: OhlcBar; prevClose: number | null }>();

        bars.forEach((bar, i) => {
            map.set(toTime(bar.date) as number, { bar, prevClose: i > 0 ? bars[i - 1].close : null });
        });

        return map;
    }, [bars]);

    useEffect(() => {
        const container = containerRef.current;

        if (!container || bars.length === 0) {
return;
}

        const decimals = priceDecimals(bars[bars.length - 1].close);

        const chart = createChart(container, {
            height,
            autoSize: true,
            layout: {
                background: { type: ColorType.Solid, color: 'transparent' },
                textColor: '#71717a',
                fontSize: 11,
                attributionLogo: false,
            },
            grid: {
                vertLines: { color: 'rgba(255,255,255,0.04)' },
                horzLines: { color: 'rgba(255,255,255,0.04)' },
            },
            crosshair: {
                mode: CrosshairMode.Magnet,
                vertLine: { color: 'rgba(161,161,170,0.4)', style: LineStyle.Dashed, labelBackgroundColor: '#27272a' },
                horzLine: { color: 'rgba(161,161,170,0.4)', style: LineStyle.Dashed, labelBackgroundColor: '#27272a' },
            },
            rightPriceScale: {
                borderColor: 'rgba(255,255,255,0.08)',
                scaleMargins: { top: 0.08, bottom: 0.22 },
            },
            timeScale: {
                borderColor: 'rgba(255,255,255,0.08)',
                timeVisible: false,
                rightOffset: 3,
            },
        });

        const candles = chart.addSeries(CandlestickSeries, {
            upColor: UP,
            downColor: DOWN,
            wickUpColor: UP,
            wickDownColor: DOWN,
            borderVisible: false,
            priceFormat: { type: 'price', precision: decimals, minMove: 1 / 10 ** decimals },
        });

        const volume = chart.addSeries(HistogramSeries, {
            priceFormat: { type: 'volume' },
            priceScaleId: 'volume',
            lastValueVisible: false,
            priceLineVisible: false,
        });

        chart.priceScale('volume').applyOptions({
            scaleMargins: { top: 0.84, bottom: 0 },
            visible: false,
        });

        candles.setData(
            bars.map((bar) => ({
                time: toTime(bar.date),
                open: bar.open,
                high: bar.high,
                low: bar.low,
                close: bar.close,
            })),
        );

        volume.setData(
            bars.map((bar) => ({
                time: toTime(bar.date),
                value: bar.volume,
                color: bar.close >= bar.open ? 'rgba(16,185,129,0.28)' : 'rgba(244,63,94,0.28)',
            })),
        );

        const barDates = new Set(bars.map((bar) => bar.date));
        const seriesMarkers: SeriesMarker<Time>[] = markers
            .filter((marker) => barDates.has(marker.date))
            .sort((a, b) => a.date.localeCompare(b.date))
            .map((marker) => ({
                time: toTime(marker.date),
                position: 'aboveBar',
                color: marker.color,
                shape: 'arrowDown',
                text: marker.label,
                size: 1,
            }));

        if (seriesMarkers.length > 0) {
            createSeriesMarkers(candles, seriesMarkers);
        }

        // Horizontal trade levels (entry, stop) as labeled price lines.
        levels.forEach((level) => {
            candles.createPriceLine({
                price: level.value,
                color: level.color,
                lineWidth: 1,
                lineStyle: LineStyle.Dashed,
                axisLabelVisible: true,
                title: level.label,
            });
        });

        chart.subscribeCrosshairMove((param) => {
            const hit = param.time !== undefined ? barByTime.get(param.time as number) : undefined;

            if (!hit) {
                setLegend(null);

                return;
            }

            setLegend({
                date: hit.bar.date,
                open: hit.bar.open,
                high: hit.bar.high,
                low: hit.bar.low,
                close: hit.bar.close,
                volume: hit.bar.volume,
                change: hit.prevClose !== null && hit.prevClose > 0 ? hit.bar.close / hit.prevClose - 1 : null,
            });
        });

        chartRef.current = chart;
        candleRef.current = candles;

        return () => {
            chart.remove();
            chartRef.current = null;
            candleRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [bars, markers, levels, height]);

    // Apply the selected range as a visible window over the loaded data.
    useEffect(() => {
        const chart = chartRef.current;

        if (!chart || bars.length === 0) {
return;
}

        const days = RANGES.find((r) => r.key === range)?.days ?? Infinity;

        if (!isFinite(days)) {
            chart.timeScale().fitContent();

            return;
        }

        const last = bars[bars.length - 1].date;
        const fromTs = Date.parse(last + 'T00:00:00Z') - days * 86400_000;
        const firstVisible = bars.find((bar) => Date.parse(bar.date + 'T00:00:00Z') >= fromTs) ?? bars[0];

        chart.timeScale().setVisibleRange({ from: toTime(firstVisible.date), to: toTime(last) });
    }, [range, bars]);

    if (bars.length === 0) {
        return <p className="py-8 text-center text-sm text-muted-foreground">No price data for this window.</p>;
    }

    const latest = bars[bars.length - 1];
    const shown = legend ?? {
        date: latest.date,
        open: latest.open,
        high: latest.high,
        low: latest.low,
        close: latest.close,
        volume: latest.volume,
        change: bars.length > 1 && bars[bars.length - 2].close > 0 ? latest.close / bars[bars.length - 2].close - 1 : null,
    };

    const changeColor = shown.change === null ? 'text-muted-foreground' : shown.change >= 0 ? 'text-emerald-400' : 'text-rose-400';

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 font-mono text-xs text-muted-foreground">
                    <span className="text-foreground">{shown.date}</span>
                    <span>
                        O <span className="text-foreground">{fmtPrice(shown.open)}</span>
                    </span>
                    <span>
                        H <span className="text-foreground">{fmtPrice(shown.high)}</span>
                    </span>
                    <span>
                        L <span className="text-foreground">{fmtPrice(shown.low)}</span>
                    </span>
                    <span>
                        C <span className="text-foreground">{fmtPrice(shown.close)}</span>
                    </span>
                    {shown.change !== null && (
                        <span className={changeColor}>
                            {shown.change >= 0 ? '+' : ''}
                            {(shown.change * 100).toFixed(2)}%
                        </span>
                    )}
                    <span>
                        Vol <span className="text-foreground">{fmtVolume(shown.volume)}</span>
                    </span>
                </div>
                <div className="flex gap-0.5 rounded-md border border-border/60 p-0.5">
                    {RANGES.map((r) => (
                        <button
                            key={r.key}
                            type="button"
                            onClick={() => setRange(r.key)}
                            className={cn(
                                'rounded px-2 py-0.5 font-mono text-xs transition-colors',
                                range === r.key
                                    ? 'bg-secondary text-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {r.key}
                        </button>
                    ))}
                </div>
            </div>
            <div ref={containerRef} style={{ height }} />
        </div>
    );
}
