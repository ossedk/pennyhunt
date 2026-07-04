<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backtest_events', function (Blueprint $table) {
            // Macro regime: CBOE VIX level and BTC 5-session return (retail
            // risk-appetite proxy) as-of the signal day.
            $table->float('vix')->nullable()->after('site_mention_z');
            $table->float('btc_ret_5d')->nullable()->after('vix');
            // Momentum continuation: consecutive days of strictly rising
            // mentions ending at the signal day (0 = one-shot spike).
            $table->unsignedSmallInteger('mention_streak')->nullable()->after('btc_ret_5d');
        });
    }

    public function down(): void
    {
        Schema::table('backtest_events', function (Blueprint $table) {
            $table->dropColumn(['vix', 'btc_ret_5d', 'mention_streak']);
        });
    }
};
