<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TickerProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'list_date' => 'date:Y-m-d',
            'synced_at' => 'datetime',
            'market_cap' => 'integer',
            'shares_outstanding' => 'integer',
            'weighted_shares_outstanding' => 'integer',
        ];
    }

    public function ticker(): BelongsTo
    {
        return $this->belongsTo(Ticker::class);
    }
}
