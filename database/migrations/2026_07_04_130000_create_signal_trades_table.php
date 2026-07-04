<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paper-trade ledger: one row per trade-tier signal, managed by the
        // exact v3 discipline the backtest validated (enter next open, 10%
        // stop, no take, day-5 time exit, pessimistic OHLC fills). This is
        // the forward test — the decision gate's live evidence.
        Schema::create('signal_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signal_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('ticker_id')->constrained();

            // pending_entry (fired, awaiting next session's open) -> open ->
            // closed; cancelled when no entry bar materializes.
            $table->string('status')->default('pending_entry')->index();
            $table->string('tier'); // trade (>= calibrated threshold at fire time)
            $table->float('confidence_at_entry')->nullable();
            $table->string('model_version')->nullable();

            $table->date('entry_date')->nullable();
            $table->decimal('entry_price', 14, 4)->nullable();
            $table->decimal('stop_price', 14, 4)->nullable();
            $table->date('time_exit_date')->nullable();

            $table->date('exit_date')->nullable();
            $table->decimal('exit_price', 14, 4)->nullable();
            $table->string('exit_reason')->nullable(); // stop | time | manual
            $table->float('exit_return')->nullable();
            $table->float('net_return')->nullable(); // exit_return - friction

            // Suggested sizing at fire time (advisory only).
            $table->float('kelly_fraction')->nullable();

            // Intraday-indicative quote for open positions (15-min refresh).
            // Authoritative exits only ever come from completed daily bars.
            $table->decimal('last_quote', 14, 4)->nullable();
            $table->timestamp('last_quote_at')->nullable();
            $table->float('unrealized_return')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_trades');
    }
};
