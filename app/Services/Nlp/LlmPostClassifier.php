<?php

namespace App\Services\Nlp;

use App\Models\PostSentiment;
use App\Models\RawPost;
use Illuminate\Support\Facades\Http;

/**
 * Structured post classification via a cheap LLM (OpenAI gpt-5-mini preferred,
 * Anthropic Haiku as fallback). Post *type* is the payload the lexicon can't
 * provide: substantive DD reads bullish for forward returns while pure
 * hype/rocket-emoji posts read bearish, even at identical polarity.
 */
class LlmPostClassifier
{
    protected const SYSTEM_PROMPT = <<<'PROMPT'
You classify retail stock-forum posts (Reddit/X) about penny stocks. Respond with ONLY a JSON object, no markdown fences, with exactly these keys:
- "post_type": one of "dd" (substantive due diligence/analysis), "technical" (chart/TA), "hype" (rockets, moon, yolo, no substance), "news" (relaying a catalyst/PR/filing), "question", "other"
- "direction": float -1.0 (bearish) to 1.0 (bullish)
- "conviction": float 0.0 to 1.0 — how strongly the author commits (position size, certainty language)
- "pump_suspicion": float 0.0 to 1.0 — coordinated promotion markers (new account vibes, copy-paste promo, unrealistic price targets, urgency, "load up before...")
- "catalyst": boolean — does the post claim a specific upcoming catalyst (FDA date, earnings, merger, contract)?
- "off_topic": boolean — true when the post is NOT about the listed stock, most commonly a crypto memecoin that shares the $SYMBOL. Set true if ANY memecoin tell is present: "CA" or a contract address (Solana addresses are 32-44 base58 chars, often referenced by their prefix); PumpFun/Moby/Raydium/DEX platforms; "CTO"/community takeover; airdrop, holders/holder count, on-chain lore; crypto KOL names or cashtags (e.g. Ansem/$ANSEM/@blknoiz06); "MC"/market-cap talk in millions when the ticker context shows a company worth billions (memecoins measure success in $1M-$100M MC — "millions MC", "million coded", "$100 million potential" for a multi-billion company means the coin, not the stock). Plain hype about the actual stock (shares, squeeze, options, earnings) is NOT off_topic.
- "relevant_tickers": array of symbols, drawn ONLY from the candidate list in the ticker context, that this post genuinely discusses as stocks. Judge each candidate independently and EXCLUDE it when its symbol merely appears as an English word or acronym in the sentence ("$NOW HIT THE BOTTOM" discusses NOW, not HIT; "the CEO said" is not $CEO), when it refers to a different company or a crypto coin, or when it is part of a spammed cashtag list the author says nothing specific about. Include a candidate the author actually analyzes, trades or hypes.
- "reasoning": one short sentence
PROMPT;

    public function enabled(): bool
    {
        return $this->provider() !== null;
    }

    /** @return 'openai'|'anthropic'|null */
    public function provider(): ?string
    {
        return match (true) {
            filled(config('pennyhunt.llm.openai_api_key')) => 'openai',
            filled(config('pennyhunt.llm.anthropic_api_key')) => 'anthropic',
            default => null,
        };
    }

    /** Classify a post and persist the result. True when stored. */
    public function classifyAndStore(RawPost $post): bool
    {
        $result = $this->classify($this->withTickerContext($post));

        if ($result === null) {
            return false;
        }

        PostSentiment::updateOrCreate(
            ['raw_post_id' => $post->id],
            [
                // Column is a string enum (bullish|bearish|neutral); the raw
                // float direction is folded into conviction's sign context.
                'llm_direction' => match (true) {
                    $result['direction'] > 0.15 => 'bullish',
                    $result['direction'] < -0.15 => 'bearish',
                    default => 'neutral',
                },
                'llm_conviction' => $result['conviction'],
                'llm_pump_suspicion' => $result['pump_suspicion'],
                'llm_post_type' => $result['post_type'],
                'llm_catalyst' => $result['catalyst'],
                'llm_off_topic' => $result['off_topic'],
                'llm_reasoning' => $result['reasoning'],
                'scored_at' => now(),
            ],
        );

        $this->pruneMentions($post, $result);

        return true;
    }

    /**
     * Mention hygiene from the LLM verdict — false mentions poison mention
     * velocity, z-scores AND the posts shown on ticker pages.
     *
     *  - off_topic tweet → drop every mention (crypto coin sharing the $tag)
     *  - relevant_tickers returned → drop bare-word mentions (method !=
     *    cashtag) whose symbol the model rejected. Cashtags survive on
     *    non-twitter sources: an explicit $TAG is the author's own claim,
     *    the LLM only vetoes weaker bare-word matches there.
     *
     * @param  array{off_topic: bool, relevant_tickers: list<string>|null}  $result
     */
    protected function pruneMentions(RawPost $post, array $result): void
    {
        if ($result['off_topic'] && $post->source?->type === 'twitter') {
            $post->mentions()->delete();

            return;
        }

        // null = model omitted the key (old cached outputs) → don't prune.
        if ($result['relevant_tickers'] === null) {
            return;
        }

        $post->mentions()
            ->where('method', '<>', 'cashtag')
            ->whereHas('ticker', fn ($q) => $q->whereNotIn('symbol', $result['relevant_tickers']))
            ->delete();
    }

    /**
     * Prefix the post text with the resolved stock identity of each cashtag,
     * so the model can tell "$WEN to a million MC" (memecoin) apart from
     * Wendy's — without context those are indistinguishable.
     */
    protected function withTickerContext(RawPost $post): string
    {
        $tickers = $post->mentions()
            ->with('ticker:id,symbol,name,exchange,market_cap')
            ->get()
            ->pluck('ticker')
            ->filter()
            ->map(function ($ticker): string {
                $cap = $ticker->market_cap !== null
                    ? ', market cap $'.number_format((float) $ticker->market_cap)
                    : '';

                return '$'.$ticker->symbol.' = '.($ticker->name ?? 'unknown')
                    .($ticker->exchange ? ' ('.$ticker->exchange.$cap.')' : $cap);
            });

        $context = $tickers->isNotEmpty()
            ? "[Ticker context — candidate stocks detected in this post (some may be false matches on common words): {$tickers->implode('; ')}]\n\n"
            : '';

        return $context.$post->fullText();
    }

    /**
     * @return array{post_type: string, direction: float, conviction: float, pump_suspicion: float, catalyst: bool, off_topic: bool, relevant_tickers: list<string>|null, reasoning: string}|null
     */
    public function classify(string $text): ?array
    {
        $raw = match ($this->provider()) {
            'openai' => $this->completeOpenAi($text),
            'anthropic' => $this->completeAnthropic($text),
            default => null,
        };

        if ($raw === null) {
            return null;
        }

        // Tolerate accidental code fences despite the prompt.
        $raw = preg_replace('/^```(?:json)?|```$/m', '', trim($raw));
        $json = json_decode(trim((string) $raw), true);

        if (! is_array($json) || ! isset($json['post_type'], $json['direction'])) {
            return null;
        }

        $types = ['dd', 'technical', 'hype', 'news', 'question', 'other'];

        return [
            'post_type' => in_array($json['post_type'], $types, true) ? $json['post_type'] : 'other',
            'direction' => max(-1.0, min(1.0, (float) $json['direction'])),
            'conviction' => max(0.0, min(1.0, (float) ($json['conviction'] ?? 0))),
            'pump_suspicion' => max(0.0, min(1.0, (float) ($json['pump_suspicion'] ?? 0))),
            'catalyst' => (bool) ($json['catalyst'] ?? false),
            'off_topic' => (bool) ($json['off_topic'] ?? false),
            // null (key absent) means "no verdict" — callers must not prune.
            'relevant_tickers' => isset($json['relevant_tickers']) && is_array($json['relevant_tickers'])
                ? array_values(array_map(fn ($s): string => strtoupper((string) $s), $json['relevant_tickers']))
                : null,
            'reasoning' => (string) ($json['reasoning'] ?? ''),
        ];
    }

    protected function completeOpenAi(string $text): ?string
    {
        $response = Http::timeout(45)
            ->withToken((string) config('pennyhunt.llm.openai_api_key'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('pennyhunt.llm.openai_model'),
                // gpt-5 family is a reasoning model. 'low' (not 'minimal')
                // effort is needed for the off_topic judgment — comparing a
                // tweet's market-cap talk against the ticker context requires
                // actual reasoning. max_completion_tokens leaves headroom for
                // hidden reasoning tokens.
                'reasoning_effort' => 'low',
                'max_completion_tokens' => 1200,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => mb_substr($text, 0, 6000)],
                ],
            ])
            ->throw();

        return $response->json('choices.0.message.content');
    }

    protected function completeAnthropic(string $text): ?string
    {
        $response = Http::timeout(45)
            ->withHeaders([
                'x-api-key' => config('pennyhunt.llm.anthropic_api_key'),
                'anthropic-version' => '2023-06-01',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('pennyhunt.llm.anthropic_model'),
                'max_tokens' => 300,
                'system' => self::SYSTEM_PROMPT,
                'messages' => [
                    ['role' => 'user', 'content' => mb_substr($text, 0, 6000)],
                ],
            ])
            ->throw();

        return $response->json('content.0.text');
    }
}
