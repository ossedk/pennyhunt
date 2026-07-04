<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketBar extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'bucket_start' => 'datetime',
            'open' => 'float',
            'high' => 'float',
            'low' => 'float',
            'close' => 'float',
        ];
    }

    public function ticker(): BelongsTo
    {
        return $this->belongsTo(Ticker::class);
    }
}
