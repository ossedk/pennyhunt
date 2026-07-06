<?php

namespace App\Http\Controllers;

use App\Jobs\Nlp\GenerateSignalBrief;
use App\Models\AlertEvent;
use App\Models\AuthorLeaderboard;
use App\Models\BacktestEvent;
use App\Models\BacktestRun;
use App\Models\MarketBar;
use App\Models\RawPost;
use App\Models\SecFiling;
use App\Models\Signal;
use App\Models\SignalModel;
use App\Models\SignalTrade;
use App\Models\TickerMetric;
use App\Services\Features\MarketIntelligence;
use App\Services\MarketData\MarketClock;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class SignalsController extends Controller
{
    /**
     * The blotter: open/pending positions with live P&L, closed-trade
     * history with the forward-test scoreboard, and the raw signal log.
     */
    public function index(MarketClock $clock): Response
    {
        $positions = SignalTrade::query()
            ->where('book', 'legacy')
            ->whereIn('status', ['pending_entry', 'open'])
            ->with(['ticker:id,symbol,name', 'signal:id,fired_at,composite_score,confidence'])
            ->orderByRaw("status = 'open' desc")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SignalTrade $t): array => $this->tradePayload($t, withHoldingDay: true));

        $closed = SignalTrade::query()
            ->where('book', 'legacy')
            ->where('status', 'closed')
            ->with(['ticker:id,symbol,name', 'signal:id,fired_at,composite_score,confidence'])
            ->orderByDesc('exit_date')
            ->limit(100)
            ->get()
            ->map(fn (SignalTrade $t): array => $this->tradePayload($t));

        $signals = Signal::query()
            ->with(['ticker:id,symbol,name,exchange', 'trade:id,signal_id,status,net_return,unrealized_return'])
            ->orderByDesc('fired_at')
            ->paginate(50)
            ->through(fn (Signal $s): array => [
                'id' => $s->id,
                'symbol' => $s->ticker->symbol,
                'name' => $s->ticker->name,
                'score' => $s->composite_score,
                'confidence' => $s->confidence,
                'model_version' => $s->model_version,
                'state' => $s->state,
                'breakdown' => $s->breakdown,
                'forward_return_1d' => $s->forward_return_1d,
                'forward_return_3d' => $s->forward_return_3d,
                'forward_return_5d' => $s->forward_return_5d,
                'graded_at' => $s->graded_at?->toIso8601String(),
                'fired_at' => $s->fired_at->toIso8601String(),
                'trade' => $s->trade?->only(['id', 'status', 'net_return', 'unrealized_return']),
            ]);

        // System trade alerts from the last two sessions (stop proximity,
        // time-exit-tomorrow, filings, mention collapse).
        $tradeAlerts = AlertEvent::query()
            ->where('kind', 'like', 'trade_%')
            ->where('created_at', '>=', now()->subDays(2))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (AlertEvent $a): array => [
                'id' => $a->id,
                'kind' => $a->kind,
                'signal_id' => $a->signal_id,
                'payload' => $a->payload,
                'created_at' => $a->created_at->toIso8601String(),
            ]);

        return Inertia::render('signals', [
            'positions' => $positions,
            'closed' => $closed,
            'signals' => $signals,
            'scoreboard' => $this->scoreboard(),
            'tradeTier' => SignalModel::active()?->metrics['trade_tier'] ?? null,
            'tradeAlerts' => $tradeAlerts,
            'marketStatus' => $clock->status(),
        ]);
    }

    /**
     * The signal cockpit: everything needed to decide take / size / skip on
     * one screen — trade plan, decision evidence vs. the backtest's winner
     * profile, historical analogs, and the social tape since the fire.
     */
    public function show(Signal $signal, MarketClock $clock): Response
    {
        $signal->load('ticker:id,symbol,name,exchange');

        $trade = SignalTrade::query()->where('signal_id', $signal->id)->where('book', 'legacy')->first();
        $model = SignalModel::active();
        $run = BacktestRun::query()->where('status', 'done')->orderByDesc('id')->first();

        $today = now()->toDateString();
        $intel = MarketIntelligence::load([$signal->ticker_id], now()->subDays(30)->toDateString(), $today)
            ->features($signal->ticker_id, $today);

        // Mention momentum: signal-day buzz vs. the most recent full day.
        $mentionCurve = TickerMetric::query()
            ->where('ticker_id', $signal->ticker_id)
            ->where('interval', '1d')
            ->where('bucket_start', '>=', $signal->fired_at->copy()->subDays(10))
            ->orderBy('bucket_start')
            ->get(['bucket_start', 'mention_count', 'unique_authors', 'zscore_mentions', 'weighted_sentiment'])
            ->map(fn (TickerMetric $m): array => [
                'day' => $m->bucket_start->toDateString(),
                'mentions' => $m->mention_count,
                'authors' => $m->unique_authors,
                'zscore' => $m->zscore_mentions,
                'sentiment' => $m->weighted_sentiment,
            ]);

        $filingsSinceFire = SecFiling::query()
            ->where('ticker_id', $signal->ticker_id)
            ->where('filed_at', '>=', $signal->fired_at)
            ->orderByDesc('filed_at')
            ->get(['form', 'filed_at'])
            ->map(fn (SecFiling $f): array => [
                'form' => $f->form,
                'filed_at' => $f->filed_at->toIso8601String(),
            ]);

        $posts = RawPost::query()
            ->whereHas('mentions', fn ($q) => $q->where('ticker_id', $signal->ticker_id))
            ->where('posted_at', '>=', $signal->fired_at->copy()->subDay())
            ->whereDoesntHave('sentiment', fn ($q) => $q->where('llm_off_topic', true))
            ->with([
                'source:id,key,name',
                'author:id,username,karma,pump_risk_score',
                'sentiment:id,raw_post_id,lexicon_score,llm_direction,llm_post_type,llm_conviction,llm_pump_suspicion',
            ])
            ->orderByDesc('score')
            ->limit(30)
            ->get()
            ->map(fn (RawPost $post): array => [
                'id' => $post->id,
                'kind' => $post->kind,
                'title' => $post->title,
                'body' => mb_substr($post->body ?? '', 0, 280),
                'permalink' => $post->permalink,
                'score' => $post->score,
                'posted_at' => $post->posted_at->toIso8601String(),
                'source' => $post->source->only(['key', 'name']),
                'author' => $post->author?->only(['username', 'karma', 'pump_risk_score']),
                'sentiment' => $post->sentiment?->only([
                    'lexicon_score', 'llm_direction', 'llm_post_type', 'llm_conviction', 'llm_pump_suspicion',
                ]),
            ]);

        // "What to look for" note: generate lazily for signals that predate
        // the brief feature (or whose generation failed on fire).
        if ($signal->llm_brief === null) {
            GenerateSignalBrief::dispatch($signal->id);
        }

        return Inertia::render('signals/show', [
            'signal' => [
                'id' => $signal->id,
                'symbol' => $signal->ticker->symbol,
                'name' => $signal->ticker->name,
                'exchange' => $signal->ticker->exchange,
                'fired_at' => $signal->fired_at->toIso8601String(),
                'score' => $signal->composite_score,
                'confidence' => $signal->confidence,
                'model_version' => $signal->model_version,
                'state' => $signal->state,
                'breakdown' => $signal->breakdown,
                'forward_return_1d' => $signal->forward_return_1d,
                'forward_return_3d' => $signal->forward_return_3d,
                'forward_return_5d' => $signal->forward_return_5d,
                'llm_brief' => $signal->llm_brief,
            ],
            'trade' => $trade !== null ? $this->tradePayload($trade->load('ticker'), withHoldingDay: true) : null,
            'tradeTier' => $model?->metrics['trade_tier'] ?? null,
            'winnerProfile' => $run?->results['winner_profile'] ?? null,
            'similar' => $this->similarSignals($signal, $run),
            'intelToday' => $intel,
            'mentionCurve' => $mentionCurve,
            'filingsSinceFire' => $filingsSinceFire,
            'posts' => $posts,
            'marketStatus' => $clock->status(),
        ]);
    }

    /**
     * Daily bars around a signal for the post-signal price chart: ~10
     * sessions of context before the fire date, everything after. Includes
     * the trade-discipline annotations (entry open, -10% stop, day-5 time
     * exit) so the chart shows what the validated strategy would have done.
     */
    public function bars(Signal $signal): JsonResponse
    {
        $firedDate = $signal->fired_at->toDateString();

        $bars = MarketBar::query()
            ->where('ticker_id', $signal->ticker_id)
            ->where('interval', '1d')
            ->where('bucket_start', '>=', $signal->fired_at->copy()->subDays(20))
            ->where('bucket_start', '<=', $signal->fired_at->copy()->addDays(45))
            ->orderBy('bucket_start')
            ->get()
            ->map(fn (MarketBar $bar): array => [
                'date' => $bar->bucket_start->toDateString(),
                'open' => (float) $bar->open,
                'high' => (float) $bar->high,
                'low' => (float) $bar->low,
                'close' => (float) $bar->close,
                'volume' => (float) $bar->volume,
            ])
            ->values();

        // Entry = open of the first session after the fire date.
        $entryBar = $bars->first(fn (array $bar): bool => $bar['date'] > $firedDate);
        $entry = $entryBar['open'] ?? null;
        $entryIdx = $entryBar !== null ? $bars->search(fn (array $bar): bool => $bar['date'] === $entryBar['date']) : null;

        return response()->json([
            'symbol' => $signal->ticker->symbol,
            'fired_date' => $firedDate,
            'bars' => $bars,
            'entry_date' => $entryBar['date'] ?? null,
            'entry' => $entry,
            'stop_level' => $entry !== null ? round($entry * 0.9, 4) : null,
            'time_exit_date' => $entryIdx !== null ? ($bars[$entryIdx + 5]['date'] ?? null) : null,
        ]);
    }

    /**
     * Hourly swarm data for the crowd-momentum animation: mention/author/
     * sentiment buckets from 48h before the fire through now (capped at
     * fire+5d), plus "named" particles — the loudest identifiable authors
     * (voice-ranked and high-karma) with their hour and sentiment. The page
     * polls this every few minutes; new hours animate in.
     */
    public function swarm(Signal $signal): JsonResponse
    {
        $start = $signal->fired_at->copy()->subHours(48);
        $end = min(now(), $signal->fired_at->copy()->addDays(5));

        $hours = TickerMetric::query()
            ->where('ticker_id', $signal->ticker_id)
            ->where('interval', '1h')
            ->where('bucket_start', '>=', $start)
            ->where('bucket_start', '<=', $end)
            ->orderBy('bucket_start')
            ->get(['bucket_start', 'mention_count', 'unique_authors', 'weighted_sentiment', 'zscore_mentions'])
            ->map(fn (TickerMetric $m): array => [
                'hour' => $m->bucket_start->toIso8601String(),
                'mentions' => $m->mention_count,
                'authors' => $m->unique_authors,
                'sentiment' => $m->weighted_sentiment !== null ? (float) $m->weighted_sentiment : null,
                'zscore' => $m->zscore_mentions !== null ? (float) $m->zscore_mentions : null,
            ]);

        $week = AuthorLeaderboard::currentWeek();
        $voiceRanks = $week === null ? [] : AuthorLeaderboard::query()
            ->where('week_start', $week)
            ->pluck('rank', 'author_id')
            ->all();

        $posts = RawPost::query()
            ->whereHas('mentions', fn ($q) => $q->where('ticker_id', $signal->ticker_id))
            ->whereBetween('posted_at', [$start, $end])
            ->whereDoesntHave('sentiment', fn ($q) => $q->where('llm_off_topic', true))
            ->whereNotNull('author_id')
            ->with([
                'author:id,username,karma',
                'sentiment:id,raw_post_id,lexicon_score,llm_direction,llm_post_type',
            ])
            ->orderByDesc('score')
            ->limit(150)
            ->get()
            ->map(fn (RawPost $p): array => [
                'hour' => $p->posted_at->copy()->startOfHour()->toIso8601String(),
                'username' => $p->author?->username,
                'karma' => $p->author?->karma,
                'voice_rank' => $voiceRanks[$p->author_id] ?? null,
                'score' => $p->score,
                'sentiment' => $p->sentiment?->llm_direction
                    ?? ($p->sentiment?->lexicon_score !== null
                        ? ($p->sentiment->lexicon_score > 0.1 ? 'bullish' : ($p->sentiment->lexicon_score < -0.1 ? 'bearish' : 'neutral'))
                        : 'neutral'),
                'post_type' => $p->sentiment?->llm_post_type,
            ]);

        return response()->json([
            'symbol' => $signal->ticker->symbol,
            'fired_at' => $signal->fired_at->toIso8601String(),
            'window' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()],
            'live' => $end->diffInMinutes(now()) < 90,
            // Display anchor: the mention z-score where the composite's
            // acceleration component saturates — "critical mass".
            'threshold_z' => 3.0,
            'hours' => $hours,
            'posts' => $posts,
        ]);
    }

    /** @return array<string, mixed> */
    protected function tradePayload(SignalTrade $t, bool $withHoldingDay = false): array
    {
        return [
            'id' => $t->id,
            'signal_id' => $t->signal_id,
            'symbol' => $t->ticker->symbol,
            'name' => $t->ticker->name,
            'status' => $t->status,
            'tier' => $t->tier,
            'confidence_at_entry' => $t->confidence_at_entry,
            'model_version' => $t->model_version,
            'fired_at' => $t->signal?->fired_at?->toIso8601String(),
            'entry_date' => $t->entry_date?->toDateString(),
            'entry_price' => $t->entry_price,
            'stop_price' => $t->stop_price,
            'time_exit_date' => $t->time_exit_date?->toDateString(),
            'exit_date' => $t->exit_date?->toDateString(),
            'exit_price' => $t->exit_price,
            'exit_reason' => $t->exit_reason,
            'exit_return' => $t->exit_return,
            'net_return' => $t->net_return,
            'kelly_fraction' => $t->kelly_fraction,
            'last_quote' => $t->last_quote,
            'last_quote_at' => $t->last_quote_at?->toIso8601String(),
            'unrealized_return' => $t->unrealized_return,
            'holding_day' => $withHoldingDay ? $t->holdingDay() : null,
        ];
    }

    /**
     * Forward-test scoreboard: the live numbers that must eventually match
     * the backtest's trade-tier expectation (+22.9%/trade gross of sizing).
     *
     * @return array<string, mixed>
     */
    protected function scoreboard(): array
    {
        $closed = SignalTrade::query()->where('book', 'legacy')->where('status', 'closed');
        $n = (clone $closed)->count();

        return [
            'open' => SignalTrade::query()->where('book', 'legacy')->whereIn('status', ['pending_entry', 'open'])->count(),
            'closed' => $n,
            'win_rate' => $n > 0 ? round((clone $closed)->where('net_return', '>', 0)->count() / $n, 3) : null,
            'avg_net' => $n > 0 ? round((float) (clone $closed)->avg('net_return'), 4) : null,
            'total_net' => $n > 0 ? round((float) (clone $closed)->sum('net_return'), 4) : null,
            'stop_rate' => $n > 0 ? round((clone $closed)->where('exit_reason', 'stop')->count() / $n, 3) : null,
            'avg_confidence' => $n > 0 ? round((float) (clone $closed)->avg('confidence_at_entry'), 4) : null,
        ];
    }

    /**
     * Historical analogs from the latest backtest: fired events in the same
     * entry-price bucket and volume-spike band. Answers "when a setup like
     * this fired in the past two years, what actually happened?"
     *
     * @return array<string, mixed>|null
     */
    protected function similarSignals(Signal $signal, ?BacktestRun $run): ?array
    {
        $gate = $signal->breakdown['market_gate'] ?? null;
        $price = $gate['close'] ?? null;
        $volumeZ = $gate['volume_z'] ?? null;

        if ($run === null || $price === null || $price <= 0) {
            return null;
        }

        $query = BacktestEvent::query()
            ->where('backtest_run_id', $run->id)
            ->where('fired', true)
            ->whereNotNull('exit_return')
            ->whereBetween('entry', [$price * 0.5, $price * 2.0]);

        if ($volumeZ !== null) {
            $query->whereBetween('volume_z', [$volumeZ - 2.0, $volumeZ + 2.0]);
        }

        $analogs = $query->get(['symbol', 'day', 'entry', 'volume_z', 'confidence', 'exit_return', 'exit_reason', 'hit']);

        if ($analogs->count() < 10) {
            return null;
        }

        $returns = $analogs->pluck('exit_return')->map(fn ($r) => (float) $r)->sort()->values();
        $pct = fn (float $p): float => (float) $returns[(int) floor($p * ($returns->count() - 1))];

        return [
            'n' => $analogs->count(),
            'hit_rate' => round($analogs->where('hit', true)->count() / $analogs->count(), 3),
            'median_exit' => round($pct(0.5), 4),
            'p90_exit' => round($pct(0.9), 4),
            'share_100pct' => round($analogs->filter(fn ($e) => (float) $e->exit_return >= 1.0)->count() / $analogs->count(), 3),
            'stop_rate' => round($analogs->where('exit_reason', 'stop')->count() / $analogs->count(), 3),
            'examples' => $analogs->sortByDesc('exit_return')->take(5)->values()->map(fn ($e) => [
                'symbol' => $e->symbol,
                'day' => $e->day,
                'exit_return' => (float) $e->exit_return,
            ])->all(),
        ];
    }
}
