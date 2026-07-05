<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorLeaderboard extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'hit_rate' => 'float',
            'wilson_lb' => 'float',
            'avg_peak_return' => 'float',
            'best_peak_return' => 'float',
            'best_call' => 'array',
            'top_tickers' => 'array',
            'recent_calls' => 'array',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    /** Latest snapshot week, or null when never built. */
    public static function currentWeek(): ?string
    {
        return static::query()->max('week_start');
    }
}
