<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsiderTrade extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'filed_at' => 'date:Y-m-d',
            'transacted_at' => 'date:Y-m-d',
            'is_officer' => 'boolean',
            'is_director' => 'boolean',
            'shares' => 'float',
            'price' => 'float',
            'value' => 'float',
        ];
    }

    public function ticker(): BelongsTo
    {
        return $this->belongsTo(Ticker::class);
    }
}
