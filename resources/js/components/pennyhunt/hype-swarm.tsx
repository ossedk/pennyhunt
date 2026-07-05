import p5 from 'p5';
import { Pause, Play } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/**
 * HypeSwarm — animated crowd-momentum visualization for a signal.
 *
 * The metaphor: every particle is crowd attention. Particles orbit a
 * momentum core whose size tracks the mention z-score; when the swarm
 * reaches critical mass (z >= threshold) the outer ring ignites. The
 * animation plays the hours from 48h before the fire through now, so you
 * literally watch the crowd build, peak and (often) disperse.
 *
 * Visual encodings:
 *  - particle color   = sentiment (emerald bullish / rose bearish / zinc neutral)
 *  - particle count   = trailing mention volume (6h half-life)
 *  - jitter           = crowd quality: hype posts vibrate, DD/news orbit calm
 *  - labeled comets   = identifiable loud authors; amber = ranked Voices
 *  - core pulse       = current mention z-score
 *  - dashed ring      = critical mass (ignites amber when crossed)
 *  - amber flash      = the moment the model fired
 */

type SwarmHour = {
    hour: string;
    mentions: number;
    authors: number;
    sentiment: number | null;
    zscore: number | null;
};

type SwarmPost = {
    hour: string;
    username: string | null;
    karma: number | null;
    voice_rank: number | null;
    score: number;
    sentiment: 'bullish' | 'bearish' | 'neutral';
    post_type: string | null;
};

type SwarmData = {
    symbol: string;
    fired_at: string;
    window: { start: string; end: string };
    live: boolean;
    threshold_z: number;
    hours: SwarmHour[];
    posts: SwarmPost[];
};

type DenseHour = {
    ts: number;
    mentions: number;
    authors: number;
    sentiment: number;
    zscore: number;
};

const COLORS = {
    bullish: [16, 185, 129] as const,
    bearish: [244, 63, 94] as const,
    neutral: [113, 113, 122] as const,
    voice: [245, 158, 11] as const,
};

const MAX_PARTICLES = 320;
const HOURS_PER_SECOND = 2.2;

type Particle = {
    angle: number;
    dist: number;
    targetDist: number;
    speed: number;
    jitter: number;
    size: number;
    color: readonly [number, number, number];
    born: number; // playhead hour index at spawn
    label?: string;
    voice?: boolean;
};

/** Dense hourly series over the window; silent hours are real zeros. */
function densify(data: SwarmData): DenseHour[] {
    const start = Date.parse(data.window.start);
    const end = Date.parse(data.window.end);
    const byTs = new Map(data.hours.map((h) => [Date.parse(h.hour), h]));
    const out: DenseHour[] = [];

    for (let ts = start; ts <= end; ts += 3600_000) {
        const h = byTs.get(ts);
        out.push({
            ts,
            mentions: h?.mentions ?? 0,
            authors: h?.authors ?? 0,
            sentiment: h?.sentiment ?? 0,
            zscore: h?.zscore ?? 0,
        });
    }

    return out;
}

export function HypeSwarm({ url, height = 420 }: { url: string; height?: number }) {
    const containerRef = useRef<HTMLDivElement>(null);
    const sketchRef = useRef<p5 | null>(null);
    const dataRef = useRef<{ dense: DenseHour[]; data: SwarmData } | null>(null);
    const playheadRef = useRef({ value: 0, playing: true, scrubbed: false });
    const [playing, setPlaying] = useState(true);
    const [loaded, setLoaded] = useState(false);
    const [live, setLive] = useState(false);
    const [hud, setHud] = useState<{ label: string; mentions: number; authors: number; z: number } | null>(null);

    // Data fetch + 5-minute refresh (new hours animate in when live).
    useEffect(() => {
        let cancelled = false;

        const load = async () => {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });

            if (!response.ok || cancelled) {
                return;
            }

            const data: SwarmData = await response.json();
            const dense = densify(data);
            const isFirst = dataRef.current === null;

            dataRef.current = { dense, data };
            setLive(data.live);
            setLoaded(true);

            if (isFirst) {
                playheadRef.current.value = 0; // start the story at the beginning
            }
        };

        void load();
        const timer = setInterval(() => void load(), 5 * 60 * 1000);

        return () => {
            cancelled = true;
            clearInterval(timer);
        };
    }, [url]);

    useEffect(() => {
        const node = containerRef.current;

        if (!node) {
            return;
        }

        const sketch = (s: p5) => {
            let particles: Particle[] = [];
            let fireFlash = 0;
            let lastWholeHour = -1;

            const center = () => ({ x: s.width / 2, y: (s.height - 26) / 2 + 4 });

            const spawn = (sentimentMix: number, playhead: number, post?: SwarmPost): Particle => {
                const roll = Math.random();
                // Sentiment mix in [-1,1] shifts the bull/bear split.
                const bullP = 0.34 + 0.3 * sentimentMix;
                const bearP = 0.22 - 0.18 * sentimentMix;
                const kind = post
                    ? post.sentiment
                    : roll < bullP
                      ? 'bullish'
                      : roll < bullP + bearP
                        ? 'bearish'
                        : 'neutral';
                const hype = post ? post.post_type === 'hype' : Math.random() < 0.35;

                return {
                    angle: Math.random() * Math.PI * 2,
                    dist: Math.max(s.width, s.height) * (0.55 + Math.random() * 0.2),
                    targetDist: 40 + Math.random() * Math.min(s.width, s.height) * 0.3,
                    speed: (0.18 + Math.random() * 0.25) * (Math.random() < 0.5 ? 1 : -1),
                    jitter: hype ? 2.6 : 0.7,
                    size: post ? Math.min(3 + Math.log10(Math.max(post.karma ?? 10, 10)) * 1.6, 9) : 1.6 + Math.random() * 2.2,
                    color: post?.voice_rank != null ? COLORS.voice : COLORS[kind as keyof typeof COLORS] ?? COLORS.neutral,
                    born: playhead,
                    label: post && (post.voice_rank != null || (post.karma ?? 0) > 20000) ? (post.username ?? undefined) : undefined,
                    voice: post?.voice_rank != null,
                };
            };

            s.setup = () => {
                s.createCanvas(node.clientWidth, height);
                s.frameRate(45);
            };

            s.windowResized = () => {
                s.resizeCanvas(node.clientWidth, height);
            };

            s.mousePressed = () => {
                // Scrub by clicking/dragging the timeline strip.
                if (s.mouseY > s.height - 26 && s.mouseY < s.height && s.mouseX >= 0 && s.mouseX <= s.width) {
                    const state = dataRef.current;

                    if (state) {
                        playheadRef.current.value = (s.mouseX / s.width) * (state.dense.length - 1);
                        playheadRef.current.scrubbed = true;
                    }
                }
            };

            s.mouseDragged = () => {
                s.mousePressed?.();
            };

            s.draw = () => {
                const state = dataRef.current;

                s.background(9, 9, 11);

                if (!state || state.dense.length === 0) {
                    return;
                }

                const { dense, data } = state;
                const ph = playheadRef.current;

                if (ph.playing && !ph.scrubbed) {
                    ph.value += (s.deltaTime / 1000) * HOURS_PER_SECOND;
                }

                ph.scrubbed = false;

                if (ph.value >= dense.length - 1) {
                    ph.value = dense.length - 1; // hold on "now" (live keeps appending)
                }

                const idx = Math.floor(ph.value);
                const hour = dense[idx];
                const firedTs = Date.parse(data.fired_at);
                const { x: cx, y: cy } = center();

                // Trailing 6h attention = how many particles should be alive.
                let trailing = 0;

                for (let i = Math.max(0, idx - 5); i <= idx; i++) {
                    trailing += dense[i].mentions * (1 - (idx - i) / 7);
                }

                const target = Math.min(Math.round(trailing * 4), MAX_PARTICLES);

                // Spawn ambient particles toward the target…
                while (particles.length < target) {
                    particles.push(spawn(hour.sentiment, ph.value));
                }

                // …and retire the oldest beyond it.
                if (particles.length > target + 20) {
                    particles.splice(0, particles.length - target);
                }

                // Named comets enter exactly at their hour.
                if (idx !== lastWholeHour) {
                    const hourIso = new Date(dense[idx].ts).toISOString().slice(0, 13);

                    data.posts
                        .filter((p) => p.hour.slice(0, 13) === hourIso)
                        .slice(0, 6)
                        .forEach((p) => particles.push(spawn(hour.sentiment, ph.value, p)));

                    if (firedTs >= dense[idx].ts && firedTs < dense[idx].ts + 3600_000) {
                        fireFlash = 1;
                    }

                    lastWholeHour = idx;
                }

                const z = hour.zscore;
                const critical = z >= data.threshold_z;

                // Critical-mass ring (2D context is guaranteed — we never use WebGL).
                const ctx = s.drawingContext as CanvasRenderingContext2D;
                const ringR = Math.min(s.width, s.height - 26) * 0.36;
                s.noFill();
                s.strokeWeight(critical ? 2 : 1);
                s.stroke(critical ? s.color(245, 158, 11, 190) : s.color(255, 255, 255, 26));
                ctx.setLineDash([6, 8]);
                s.circle(cx, cy, ringR * 2);
                ctx.setLineDash([]);

                // Momentum core: radius + pulse from z-score.
                const coreR = 12 + Math.min(Math.max(z, 0), 6) * 9 + Math.sin(s.frameCount * 0.08) * (2 + z);
                for (let g = 4; g >= 1; g--) {
                    s.noStroke();
                    s.fill(critical ? s.color(245, 158, 11, 14 * g) : s.color(16, 185, 129, 10 * g));
                    s.circle(cx, cy, coreR * 2 + g * 16);
                }
                s.fill(critical ? s.color(245, 158, 11, 220) : s.color(16, 185, 129, 200));
                s.circle(cx, cy, coreR * 2);

                // Fire flash: an expanding amber ring the moment the playhead
                // crosses the fire hour, decaying over ~1.5s.
                if (fireFlash > 0) {
                    s.noFill();
                    s.stroke(245, 158, 11, 220 * fireFlash);
                    s.strokeWeight(3);
                    s.circle(cx, cy, ringR * 2 * (2 - fireFlash));
                    s.strokeWeight(1);
                    fireFlash = Math.max(fireFlash - s.deltaTime / 1500, 0);
                }

                // Particles.
                particles.forEach((p) => {
                    p.dist += (p.targetDist - p.dist) * 0.028;
                    p.angle += (p.speed * s.deltaTime) / 1000;
                    const jx = (Math.random() - 0.5) * p.jitter;
                    const jy = (Math.random() - 0.5) * p.jitter;
                    const px = cx + Math.cos(p.angle) * p.dist + jx;
                    const py = cy + Math.sin(p.angle) * p.dist * 0.82 + jy;
                    const [r, g, b] = p.color;

                    s.noStroke();

                    if (p.voice) {
                        s.fill(r, g, b, 60);
                        s.circle(px, py, p.size * 3);
                    }

                    s.fill(r, g, b, p.voice ? 230 : 165);
                    s.circle(px, py, p.size);

                    if (p.label) {
                        s.fill(r, g, b, 210);
                        s.textSize(9);
                        s.textAlign(s.LEFT, s.CENTER);
                        s.text(p.label, px + p.size + 3, py);
                    }
                });

                // Timeline strip.
                const stripY = s.height - 22;
                s.fill(255, 255, 255, 12);
                s.rect(0, stripY, s.width, 22);

                // Mention histogram inside the strip.
                const maxMentions = Math.max(...dense.map((h) => h.mentions), 1);
                const barW = s.width / dense.length;

                dense.forEach((h, i) => {
                    const bh = Math.max((h.mentions / maxMentions) * 18, h.mentions > 0 ? 1.5 : 0);
                    s.fill(i <= idx ? s.color(16, 185, 129, 150) : s.color(255, 255, 255, 40));
                    s.rect(i * barW, stripY + 20 - bh, Math.max(barW - 0.5, 0.5), bh);
                });

                // Fire marker + playhead cursor.
                const fireIdx = dense.findIndex((h) => firedTs >= h.ts && firedTs < h.ts + 3600_000);

                if (fireIdx >= 0) {
                    s.stroke(245, 158, 11, 220);
                    s.line(fireIdx * barW, stripY, fireIdx * barW, s.height);
                }

                s.stroke(255, 255, 255, 190);
                s.line((ph.value / (dense.length - 1)) * s.width, stripY, (ph.value / (dense.length - 1)) * s.width, s.height);
                s.noStroke();

                // HUD sync (throttled).
                if (s.frameCount % 12 === 0) {
                    setHud({
                        label: new Date(hour.ts).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit' }),
                        mentions: hour.mentions,
                        authors: hour.authors,
                        z: hour.zscore,
                    });
                }
            };

        };

        sketchRef.current = new p5(sketch, node);

        return () => {
            sketchRef.current?.remove();
            sketchRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [height]);

    useEffect(() => {
        playheadRef.current.playing = playing;
    }, [playing]);

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => setPlaying(!playing)}
                        className="rounded-md border border-border/60 p-1.5 text-muted-foreground transition-colors hover:text-foreground"
                        title={playing ? 'Pause' : 'Play'}
                    >
                        {playing ? <Pause className="size-3.5" /> : <Play className="size-3.5" />}
                    </button>
                    {hud && (
                        <span className="font-mono text-xs text-muted-foreground">
                            {hud.label} · {hud.mentions} mentions/h · {hud.authors} authors ·{' '}
                            <span className={hud.z >= 3 ? 'text-amber-400' : ''}>z {hud.z.toFixed(1)}</span>
                        </span>
                    )}
                </div>
                <div className="flex items-center gap-3 text-[10px] text-muted-foreground">
                    {live && (
                        <span className="flex items-center gap-1 font-mono text-emerald-400">
                            <span className="inline-block size-1.5 animate-pulse rounded-full bg-emerald-400" /> LIVE ·
                            refreshes hourly
                        </span>
                    )}
                    <span className="flex items-center gap-1">
                        <span className="inline-block size-2 rounded-full bg-emerald-500" /> bullish
                    </span>
                    <span className="flex items-center gap-1">
                        <span className="inline-block size-2 rounded-full bg-rose-500" /> bearish
                    </span>
                    <span className="flex items-center gap-1">
                        <span className="inline-block size-2 rounded-full bg-amber-500" /> ranked voice
                    </span>
                    <span className="hidden sm:inline">dashed ring = critical mass · click timeline to scrub</span>
                </div>
            </div>
            <div ref={containerRef} className="overflow-hidden rounded-md border border-border/60" style={{ height }}>
                {!loaded && (
                    <p className="py-16 text-center text-sm text-muted-foreground">Loading crowd history…</p>
                )}
            </div>
        </div>
    );
}
