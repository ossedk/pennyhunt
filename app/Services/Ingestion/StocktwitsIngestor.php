<?php

namespace App\Services\Ingestion;

use App\Jobs\Pipeline\ProcessRawPost;
use App\Models\Author;
use App\Models\PostTickerMention;
use App\Models\RawPost;
use App\Models\Source;
use App\Models\Ticker;

/**
 * Maps Stocktwits messages into the normal pipeline. Symbols arrive as
 * STRUCTURED tags (the author explicitly tagged the ticker), so mentions
 * are created directly at cashtag confidence — then the post flows through
 * ProcessRawPost for sentiment/LLM like everything else.
 */
class StocktwitsIngestor
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return int posts ingested
     */
    public function ingest(Source $source, array $messages): int
    {
        $ingested = 0;

        foreach ($messages as $message) {
            $externalId = $message['id'] ?? null;
            $body = trim((string) ($message['body'] ?? ''));

            if ($externalId === null || $body === '') {
                continue;
            }

            if (RawPost::query()->where('source_id', $source->id)->where('external_id', 'st_'.$externalId)->exists()) {
                continue;
            }

            $user = $message['user'] ?? [];

            $author = Author::firstOrCreate(
                ['platform' => 'stocktwits', 'username' => (string) ($user['username'] ?? 'unknown')],
                ['stats' => []],
            );

            $author->forceFill([
                'karma' => (int) ($user['followers'] ?? 0),
                'account_created_at' => isset($user['join_date']) ? $user['join_date'].' 00:00:00' : $author->account_created_at,
                'stats' => [
                    ...($author->stats ?? []),
                    'followers' => (int) ($user['followers'] ?? 0),
                    'ideas' => (int) ($user['ideas'] ?? 0),
                    'is_verified' => (bool) ($user['official'] ?? false),
                ],
            ])->save();

            $post = RawPost::create([
                'source_id' => $source->id,
                'external_id' => 'st_'.$externalId,
                'kind' => 'post',
                'author_id' => $author->id,
                'title' => null,
                'body' => mb_substr($body, 0, 10000),
                'permalink' => 'https://stocktwits.com/'.($user['username'] ?? 'user').'/message/'.$externalId,
                'score' => (int) data_get($message, 'likes.total', 0),
                'num_comments' => (int) data_get($message, 'conversation.replies', 0),
                'posted_at' => $message['created_at'] ?? now(),
                'ingested_at' => now(),
                'meta' => [
                    'sentiment' => data_get($message, 'entities.sentiment.basic'), // Bullish|Bearish|null
                ],
            ]);

            // Structured symbol tags = author-explicit mentions (cashtag tier).
            $symbols = collect($message['symbols'] ?? [])
                ->pluck('symbol')
                ->map(fn ($s): string => strtoupper((string) $s))
                ->filter(fn (string $s): bool => preg_match('/^[A-Z]{1,5}$/', $s) === 1)
                ->unique()
                ->take(6); // tag-spam guard: messages tagging everything say nothing

            $tickers = Ticker::query()->whereIn('symbol', $symbols)->where('is_active', true)->pluck('id', 'symbol');

            foreach ($symbols as $symbol) {
                if (! isset($tickers[$symbol])) {
                    continue;
                }

                PostTickerMention::firstOrCreate(
                    ['raw_post_id' => $post->id, 'ticker_id' => $tickers[$symbol]],
                    ['confidence' => 1.0, 'method' => 'cashtag', 'posted_at' => $post->posted_at],
                );
            }

            ProcessRawPost::dispatch($post->id)->onQueue('pipeline');
            $ingested++;
        }

        return $ingested;
    }
}
