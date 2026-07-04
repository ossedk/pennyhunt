<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'enabled' => 'boolean',
            'last_polled_at' => 'datetime',
            'last_ok_at' => 'datetime',
        ];
    }

    public function rawPosts(): HasMany
    {
        return $this->hasMany(RawPost::class);
    }

    public function markPolled(): void
    {
        $this->forceFill([
            'last_polled_at' => now(),
            'last_ok_at' => now(),
            'last_error' => null,
            'consecutive_failures' => 0,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'last_polled_at' => now(),
            'last_error' => mb_substr($error, 0, 2000),
            'consecutive_failures' => $this->consecutive_failures + 1,
        ])->save();
    }
}
