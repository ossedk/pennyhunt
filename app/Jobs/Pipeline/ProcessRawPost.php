<?php

namespace App\Jobs\Pipeline;

use App\Models\PostSentiment;
use App\Models\PostTickerMention;
use App\Models\RawPost;
use App\Models\Ticker;
use App\Services\Nlp\LexiconSentiment;
use App\Services\Nlp\TickerExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pipeline stage run for every ingested post:
 *  1. extract ticker mentions (cashtag / validated symbol)
 *  2. compute tier-0 lexicon sentiment
 *
 * FinBERT (tier 1) and LLM (tier 2) scoring are appended by the NLP sidecar
 * integration in phase 2b; this job records full-coverage baselines.
 */
class ProcessRawPost implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $rawPostId)
    {
        $this->onQueue('pipeline');
    }

    public function handle(TickerExtractor $extractor, LexiconSentiment $sentiment): void
    {
        $post = RawPost::find($this->rawPostId);

        if ($post === null) {
            return;
        }

        $text = $post->fullText();

        $symbols = $extractor->extract($text);

        // Tier 2: LLM classification for ticker-mentioning posts (key-gated,
        // daily spend cap). Tweets skip the length floor: they're short by
        // nature and the off_topic verdict (crypto coin sharing the $symbol)
        // is exactly what we need it for.
        $minLength = $post->source?->type === 'twitter' ? 0 : (int) config('pennyhunt.llm.min_text_length');

        if ($symbols !== []
            && (filled(config('pennyhunt.llm.openai_api_key')) || filled(config('pennyhunt.llm.anthropic_api_key')))
            && mb_strlen($text) >= $minLength
            && ClassifyPostWithLlm::underDailyCap()) {
            ClassifyPostWithLlm::dispatch($post->id);
        }

        if ($symbols !== []) {
            $tickers = Ticker::query()
                ->whereIn('symbol', array_keys($symbols))
                ->pluck('id', 'symbol');

            foreach ($symbols as $symbol => $match) {
                $tickerId = $tickers[$symbol] ?? null;

                if ($tickerId === null) {
                    continue;
                }

                PostTickerMention::firstOrCreate(
                    ['raw_post_id' => $post->id, 'ticker_id' => $tickerId],
                    [
                        'confidence' => $match['confidence'],
                        'method' => $match['method'],
                        'posted_at' => $post->posted_at,
                    ],
                );
            }
        }

        PostSentiment::updateOrCreate(
            ['raw_post_id' => $post->id],
            [
                'lexicon_score' => $sentiment->score($text),
                'scored_at' => now(),
            ],
        );

        $post->forceFill([
            'mentions_extracted' => true,
            'sentiment_scored' => true,
        ])->save();
    }
}
