<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'day' => 'date:Y-m-d',
            'entry_date' => 'date:Y-m-d',
            'exit_date' => 'date:Y-m-d',
            'fired' => 'boolean',
            'hit' => 'boolean',
            'confidence' => 'float',
            'short_ratio' => 'float',
            'atm_filed_90d' => 'boolean',
            'active_shelf' => 'boolean',
            'share_growth_12m' => 'float',
            'market_ret_5d' => 'float',
            'site_mention_z' => 'float',
            'vix' => 'float',
            'btc_ret_5d' => 'float',
            'mention_streak' => 'integer',
            'llm_coverage' => 'float',
            'llm_direction' => 'float',
            'llm_conviction' => 'float',
            'llm_pump_suspicion' => 'float',
            'llm_dd_share' => 'float',
            'llm_hype_share' => 'float',
            'llm_news_share' => 'float',
            'llm_catalyst_share' => 'float',
        ];
    }

    public function run(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'backtest_run_id');
    }
}
