<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GBM walk-forward (out-of-sample) probability per event — the
        // honest tier signal for exit-lab slicing. The existing
        // `confidence` column keeps the logistic walk-forward score.
        Schema::table('backtest_events', function (Blueprint $table) {
            $table->decimal('gbm_confidence', 8, 6)->nullable();
        });

        // Parallel paper books: legacy (validated v3 discipline) vs phase_e
        // (no-chase entry conditions, close-based wide stop, 5d hold).
        Schema::table('signal_trades', function (Blueprint $table) {
            $table->dropUnique(['signal_id']);
            $table->string('book', 20)->default('legacy');
            $table->unique(['signal_id', 'book']);
        });
    }

    public function down(): void
    {
        Schema::table('signal_trades', function (Blueprint $table) {
            $table->dropUnique(['signal_id', 'book']);
            $table->dropColumn('book');
            $table->unique(['signal_id']);
        });
        Schema::table('backtest_events', function (Blueprint $table) {
            $table->dropColumn('gbm_confidence');
        });
    }
};
