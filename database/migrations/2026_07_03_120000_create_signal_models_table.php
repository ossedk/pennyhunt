<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Persisted confidence models (walk-forward logistic regression over
        // backtest_events). The active model scores live signals at fire time.
        Schema::create('signal_models', function (Blueprint $table) {
            $table->id();
            $table->string('version')->unique(); // e.g. v2026-07-03-1
            $table->foreignId('backtest_run_id')->nullable()->constrained()->nullOnDelete();
            $table->date('train_from');
            $table->date('train_to');
            $table->unsignedInteger('train_events');
            // weights/bias/means/sds keyed by feature name
            $table->jsonb('parameters');
            // brier, base_rate, reliability deciles, precision@k
            $table->jsonb('metrics');
            $table->boolean('is_active')->default(false);
            $table->timestampsTz();
        });

        Schema::table('backtest_events', function (Blueprint $table) {
            // Walk-forward P(hit) — trained only on events before this one.
            $table->float('confidence')->nullable()->after('classification');
            // Calendar date of the simulated exit (for portfolio simulation).
            $table->date('exit_date')->nullable()->after('exit_day');
        });

        Schema::table('signals', function (Blueprint $table) {
            $table->float('confidence')->nullable()->after('composite_score');
            $table->string('model_version')->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropColumn(['confidence', 'model_version']);
        });

        Schema::table('backtest_events', function (Blueprint $table) {
            $table->dropColumn(['confidence', 'exit_date']);
        });

        Schema::dropIfExists('signal_models');
    }
};
