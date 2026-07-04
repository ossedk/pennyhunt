<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostSentiment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'lexicon_score' => 'float',
            'finbert_score' => 'float',
            'llm_conviction' => 'float',
            'llm_pump_suspicion' => 'float',
            'llm_catalyst' => 'boolean',
            'llm_off_topic' => 'boolean',
            'scored_at' => 'datetime',
        ];
    }

    public function rawPost(): BelongsTo
    {
        return $this->belongsTo(RawPost::class);
    }

    /**
     * Best available sentiment: LLM > FinBERT > lexicon.
     */
    public function effectiveScore(): ?float
    {
        if ($this->llm_direction !== null) {
            $sign = match ($this->llm_direction) {
                'bullish' => 1,
                'bearish' => -1,
                default => 0,
            };

            return $sign * ($this->llm_conviction ?? 0.5);
        }

        if ($this->finbert_label !== null && $this->finbert_label !== 'neutral') {
            return ($this->finbert_label === 'positive' ? 1 : -1) * ($this->finbert_score ?? 0.5);
        }

        return $this->lexicon_score;
    }
}
