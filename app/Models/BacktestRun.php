<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestRun extends Model
{
    protected $guarded = [];

    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BacktestEvent::class);
    }

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'results' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
