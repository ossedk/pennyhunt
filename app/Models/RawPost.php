<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RawPost extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'ingested_at' => 'datetime',
            'meta' => 'array',
            'mentions_extracted' => 'boolean',
            'sentiment_scored' => 'boolean',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(PostTickerMention::class);
    }

    public function sentiment(): HasOne
    {
        return $this->hasOne(PostSentiment::class);
    }

    public function fullText(): string
    {
        return trim(($this->title ?? '').' '.($this->body ?? ''));
    }
}
