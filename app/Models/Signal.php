<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Signal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fired_at' => 'datetime',
            'graded_at' => 'datetime',
            'breakdown' => 'array',
            'llm_brief' => 'array',
            'llm_brief_at' => 'datetime',
            'composite_score' => 'float',
            'confidence' => 'float',
            'forward_return_1d' => 'float',
            'forward_return_3d' => 'float',
            'forward_return_5d' => 'float',
        ];
    }

    public function ticker(): BelongsTo
    {
        return $this->belongsTo(Ticker::class);
    }

    public function trade(): HasOne
    {
        return $this->hasOne(SignalTrade::class);
    }
}
