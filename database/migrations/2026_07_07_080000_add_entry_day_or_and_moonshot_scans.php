<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entry-day opening-range features (the ENTRY session's first 30
        // minutes — the signal-day day-0 columns describe the day before).
        // Ground truth for the "only fill when the open confirms" rule.
        Schema::table('backtest_events', function (Blueprint $table) {
            $table->decimal('entry_or_return', 10, 4)->nullable();
            $table->decimal('entry_vwap_dist', 10, 4)->nullable();
            $table->boolean('entry_gap_faded')->nullable();
        });

        // Every moonshot-head score the live engine computes, fired or not —
        // the Desk radar reads "who is approaching the band" from here.
        Schema::create('moonshot_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->decimal('p', 8, 6);
            $table->boolean('fired')->default(false);
            $table->string('blocked_by', 30)->nullable(); // gate that stopped it
            $table->timestampTz('scanned_at');
            $table->timestampsTz();

            $table->index(['scanned_at']);
            $table->index(['ticker_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moonshot_scans');
        Schema::table('backtest_events', function (Blueprint $table) {
            $table->dropColumn(['entry_or_return', 'entry_vwap_dist', 'entry_gap_faded']);
        });
    }
};
