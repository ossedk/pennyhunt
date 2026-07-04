<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticker extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_active' => 'boolean',
            'is_ambiguous' => 'boolean',
            'last_price' => 'float',
        ];
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(PostTickerMention::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(TickerMetric::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    public function bars(): HasMany
    {
        return $this->hasMany(MarketBar::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(TickerProfile::class);
    }

    public function financials(): HasMany
    {
        return $this->hasMany(TickerFinancial::class);
    }
}
