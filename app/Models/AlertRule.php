<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'enabled' => 'boolean',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticker(): BelongsTo
    {
        return $this->belongsTo(Ticker::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }
}
