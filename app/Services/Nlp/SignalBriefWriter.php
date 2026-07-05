<?php

namespace App\Services\Nlp;

use App\Models\RawPost;
use App\Models\Signal;
use App\Models\TickerNews;
use Illuminate\Support\Facades\Http;

/**
 * Writes the per-signal "what to look for" note: a trader-facing summary of
 * why this signal fired, what confirms the move, and what kills it. The LLM
 * gets a CLOSED WORLD built from the signal's own stored breakdown (the
 * exact features the model scored), the trade discipline, and recent
 * posts/headlines — it may only reference what's in the context.
 */
class SignalBriefWriter
{
    protected const SYSTEM_PROMPT = <<<'PROMPT'
You are a trading-desk analyst annotating a model signal on a penny stock. You receive JSON context: the signal's feature breakdown (social buzz z-scores, volume confirmation, dilution/short-flow flags, technicals like RVOL and 52-week-high distance, sector heat, insider activity, LLM crowd-quality reads), the validated trade discipline, and recent posts/headlines.

Write a note the trader reads in 30 seconds before deciding. Rules:
- ONLY use facts present in the context. Never invent numbers, catalysts or tickers.
- All return/ratio fields are decimal fractions (0.3 = 30%); convert to percentages in prose.
- Be specific about CONFIRMATION (what should happen next if this is real: volume holding, sector peers moving, breadth widening) and INVALIDATION (what kills the thesis: mention collapse, offering filing, stop level).
- Flag dilution capacity (active shelf / recent 424B), pump suspicion and crowd quality honestly — the trader must know when buzz is manufactured.
- Tone: sober, concrete, no hype, no disclaimers, no "do your own research".

Return ONLY JSON:
{
 "summary": "2-3 sentences: why it fired and the honest quality read",
 "watch_for": ["3-4 bullets, <=110 chars each: concrete confirmation signs"],
 "invalidation": "one sentence: what kills the thesis",
 "risk": "one sentence naming the biggest risk in this specific setup"
}
PROMPT;

    public function enabled(): bool
    {
        return filled(config('pennyhunt.llm.openai_api_key'));
    }

    /** Generates and stores the brief on the signal. Null when disabled/unusable. */
    public function write(Signal $signal): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $context = $this->buildContext($signal);

        $raw = $this->complete(json_encode($context, JSON_UNESCAPED_SLASHES));
        $brief = $this->validate($raw);

        if ($brief === null) {
            return null;
        }

        $signal->forceFill([
            'llm_brief' => $brief,
            'llm_brief_at' => now(),
        ])->save();

        return $brief;
    }

    /** @return array<string, mixed> */
    protected function buildContext(Signal $signal): array
    {
        $signal->loadMissing('ticker:id,symbol,name,exchange,market_cap,last_price');

        $posts = RawPost::query()
            ->whereHas('mentions', fn ($q) => $q->where('ticker_id', $signal->ticker_id))
            ->where('posted_at', '>=', $signal->fired_at->copy()->subDay())
            ->whereDoesntHave('sentiment', fn ($q) => $q->where('llm_off_topic', true))
            ->orderByDesc('score')
            ->limit(8)
            ->get(['id', 'title', 'body', 'score'])
            ->map(fn (RawPost $p): array => [
                'score' => $p->score,
                'text' => mb_substr(trim(($p->title ?? '').' '.($p->body ?? '')), 0, 200),
            ]);

        $news = TickerNews::query()
            ->where('ticker_id', $signal->ticker_id)
            ->where('published_at', '>=', $signal->fired_at->copy()->subDays(7))
            ->orderByDesc('published_at')
            ->limit(6)
            ->get(['title', 'catalyst_type', 'published_at'])
            ->map(fn (TickerNews $n): array => [
                'title' => mb_substr($n->title, 0, 140),
                'catalyst_type' => $n->catalyst_type,
                'published_at' => $n->published_at->toDateString(),
            ]);

        return [
            'ticker' => [
                'symbol' => $signal->ticker->symbol,
                'name' => $signal->ticker->name,
                'exchange' => $signal->ticker->exchange,
                'market_cap' => $signal->ticker->market_cap,
                'last_price' => $signal->ticker->last_price,
            ],
            'signal' => [
                'fired_at' => $signal->fired_at->toIso8601String(),
                'composite_score' => $signal->composite_score,
                'model_confidence' => $signal->confidence,
                'breakdown' => $signal->breakdown,
            ],
            'trade_discipline' => [
                'entry' => 'next session open',
                'stop' => '-10% from entry',
                'time_exit' => 'close of the 5th session',
                'validated_hit_definition' => '+30% peak close within 5 sessions',
            ],
            'top_posts_last_24h' => $posts,
            'news_last_7d' => $news,
        ];
    }

    /** @return array{summary: string, watch_for: list<string>, invalidation: string, risk: string}|null */
    protected function validate(?string $raw): ?array
    {
        $json = json_decode(trim((string) $raw), true);

        if (! is_array($json) || ! isset($json['summary'], $json['watch_for']) || ! is_array($json['watch_for'])) {
            return null;
        }

        return [
            'summary' => mb_substr((string) $json['summary'], 0, 600),
            'watch_for' => array_values(array_map(
                fn ($w): string => mb_substr((string) $w, 0, 160),
                array_slice($json['watch_for'], 0, 5),
            )),
            'invalidation' => mb_substr((string) ($json['invalidation'] ?? ''), 0, 300),
            'risk' => mb_substr((string) ($json['risk'] ?? ''), 0, 300),
        ];
    }

    protected function complete(string $context): ?string
    {
        $response = Http::timeout(90)
            ->retry(2, 3000, throw: false)
            ->withToken((string) config('pennyhunt.llm.openai_api_key'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('pennyhunt.llm.openai_model'),
                'reasoning_effort' => 'low',
                'max_completion_tokens' => 1800,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $context],
                ],
            ]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('choices.0.message.content');
    }
}
