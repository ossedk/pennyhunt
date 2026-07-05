<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorCall extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'called_at' => 'datetime',
            'entry_date' => 'date',
            'entry_price' => 'float',
            'peak_return' => 'float',
            'day5_return' => 'float',
            'runup_3d' => 'float',
            'graded_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function ticker(): BelongsTo
    {
        return $this->belongsTo(Ticker::class);
    }

    public function rawPost(): BelongsTo
    {
        return $this->belongsTo(RawPost::class);
    }
}
