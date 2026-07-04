<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TickerFinancial extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'end_date' => 'date:Y-m-d',
            'filing_date' => 'date:Y-m-d',
            'revenue' => 'float',
            'operating_expenses' => 'float',
            'net_income' => 'float',
            'eps_basic' => 'float',
            'operating_cash_flow' => 'float',
            'cash' => 'float',
            'current_assets' => 'float',
            'current_liabilities' => 'float',
            'total_assets' => 'float',
            'total_liabilities' => 'float',
            'equity' => 'float',
        ];
    }

    public function ticker(): BelongsTo
    {
        return $this->belongsTo(Ticker::class);
    }
}
