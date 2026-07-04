<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketBrief extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'brief' => 'array',
            'context' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->orderByDesc('generated_at')->first();
    }
}
