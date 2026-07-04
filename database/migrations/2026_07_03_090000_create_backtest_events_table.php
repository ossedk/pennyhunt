<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per scored candidate day (fired signal OR control) so the UI
        // can paginate all signals and analysis/weight-fitting can query the
        // full feature set without selection bias.
        Schema::create('backtest_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->string('symbol'); // denormalized for list rendering
            $table->date('day');
            $table->boolean('fired');

            // Signal-day features (all as-of, no look-ahead)
            $table->float('composite');
            $table->float('zscore');
            $table->integer('mentions');
            $table->integer('unique_authors');
            $table->float('sentiment')->nullable();
            $table->float('volume_z')->nullable();
            $table->float('dollar_volume')->nullable();
            $table->float('pre_return_3d')->nullable();

            // Outcome (entry next trading day at open)
            $table->date('entry_date');
            $table->decimal('entry', 16, 6);
            $table->float('return_1d');
            $table->float('return_3d');
            $table->float('return_5d');
            $table->float('best_close_5d');
            $table->boolean('hit');
            $table->string('classification'); // prediction | reaction

            $table->timestampTz('created_at')->nullable();

            $table->index(['backtest_run_id', 'fired', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_events');
    }
};
