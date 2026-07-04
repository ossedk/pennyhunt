<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TickerMetric extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'bucket_start' => 'datetime',
            'avg_sentiment' => 'float',
            'weighted_sentiment' => 'float',
            'author_quality_avg' => 'float',
            'zscore_mentions' => 'float',
            'zscore_sentiment' => 'float',
        ];
    }

    public function ticker(): BelongsTo
    {
        return $this->belongsTo(Ticker::class);
    }
}
