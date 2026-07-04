<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecFiling extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** Registration statements that put dilution "on the shelf". */
    public const SHELF_FORMS = ['S-3', 'S-3/A', 'F-3', 'F-3/A', 'S-3ASR', 'F-3ASR'];

    /** Prospectus takedowns — shares actually being sold (ATM et al.). */
    public const TAKEDOWN_FORMS = ['424B3', '424B4', '424B5'];

    protected function casts(): array
    {
        return [
            'filed_at' => 'date:Y-m-d',
        ];
    }

    public function ticker(): BelongsTo
    {
        return $this->belongsTo(Ticker::class);
    }
}
