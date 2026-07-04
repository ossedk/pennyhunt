<?php

namespace App\Http\Controllers;

use App\Models\RawPost;
use App\Models\SignalTrade;
use App\Models\Source;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'source' => ['nullable', 'string'],
            'symbol' => ['nullable', 'string', 'max:10'],
            'kind' => ['nullable', 'in:post,comment'],
            'post_type' => ['nullable', 'in:dd,technical,news,hype,question,other'],
            'positions' => ['nullable', 'in:1'],
        ]);

        // "Positions only": the tape for tickers we currently hold.
        $positionTickerIds = ($validated['positions'] ?? null) === '1'
            ? SignalTrade::query()->whereIn('status', ['pending_entry', 'open'])->pluck('ticker_id')
            : null;

        $posts = RawPost::query()
            ->with([
                'source:id,key,name',
                'author:id,username,karma,pump_risk_score',
                'sentiment:id,raw_post_id,lexicon_score,llm_direction,llm_post_type,llm_conviction,llm_pump_suspicion,llm_off_topic',
                'mentions.ticker:id,symbol',
            ])
            ->when($validated['source'] ?? null, fn ($q, $key) => $q->whereHas('source', fn ($s) => $s->where('key', $key)))
            ->when($validated['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when(
                $validated['symbol'] ?? null,
                fn ($q, $symbol) => $q->whereHas(
                    'mentions.ticker',
                    fn ($t) => $t->where('symbol', strtoupper($symbol)),
                ),
            )
            ->when(
                $validated['post_type'] ?? null,
                fn ($q, $type) => $q->whereHas('sentiment', fn ($s) => $s->where('llm_post_type', $type)),
            )
            ->when(
                $positionTickerIds,
                fn ($q, $ids) => $q->whereHas('mentions', fn ($m) => $m->whereIn('ticker_id', $ids)),
            )
            // LLM-flagged off-topic posts (crypto tokens sharing a $symbol,
            // airdrop promos) stay out of the human tape.
            ->whereDoesntHave('sentiment', fn ($q) => $q->where('llm_off_topic', true))
            ->orderByDesc('posted_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (RawPost $post): array => [
                'id' => $post->id,
                'kind' => $post->kind,
                'title' => $post->title,
                'body' => mb_substr($post->body ?? '', 0, 500),
                'permalink' => $post->permalink,
                'score' => $post->score,
                'num_comments' => $post->num_comments,
                'posted_at' => $post->posted_at->toIso8601String(),
                'source' => $post->source->only(['key', 'name']),
                'author' => $post->author?->only(['username', 'karma', 'pump_risk_score']),
                'sentiment' => $post->sentiment?->only([
                    'lexicon_score', 'llm_direction', 'llm_post_type', 'llm_conviction', 'llm_pump_suspicion',
                ]),
                'tickers' => $post->mentions->map(fn ($m) => [
                    'symbol' => $m->ticker->symbol,
                    'confidence' => $m->confidence,
                ])->values(),
            ]);

        return Inertia::render('feed', [
            'posts' => $posts,
            'sources' => Source::query()->orderBy('name')->get(['id', 'key', 'name', 'type']),
            'filters' => $validated,
        ]);
    }
}
