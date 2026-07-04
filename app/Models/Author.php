<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Author extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'account_created_at' => 'datetime',
            'stats' => 'array',
            'pump_risk_score' => 'float',
            'track_record_score' => 'float',
        ];
    }

    public function rawPosts(): HasMany
    {
        return $this->hasMany(RawPost::class);
    }

    /**
     * A rough 0..1 quality weight combining account age and karma,
     * penalized by pump risk. Used to weight sentiment contributions.
     */
    public function qualityWeight(): float
    {
        $ageDays = $this->account_created_at?->diffInDays(now()) ?? 0;
        $ageComponent = min($ageDays / 730, 1.0) * 0.5; // maxes out at 2 years
        $karmaComponent = min(log10(max($this->karma ?? 0, 1)) / 5, 1.0) * 0.5; // maxes at 100k karma

        return max(($ageComponent + $karmaComponent) * (1 - $this->pump_risk_score), 0.05);
    }
}
