<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backtest_events', function (Blueprint $table) {
            // Walk-forward OOS scores from the auxiliary heads:
            // moonshot = P(best close >= +75% in 5 sessions),
            // meta = P(phase-E-discipline trade of this event nets > 0).
            $table->decimal('moonshot_confidence', 8, 6)->nullable();
            $table->decimal('meta_confidence', 8, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backtest_events', function (Blueprint $table) {
            $table->dropColumn(['moonshot_confidence', 'meta_confidence']);
        });
    }
};
