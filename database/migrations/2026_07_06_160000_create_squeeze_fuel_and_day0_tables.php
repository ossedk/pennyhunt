<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FINRA bi-monthly short interest (Rule 4560). Point-in-time via
        // settlement_date + publication lag applied at feature-read time.
        Schema::create('short_interest', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->date('settlement_date');
            $table->unsignedBigInteger('shares_short');
            $table->decimal('days_to_cover', 10, 2)->nullable();
            $table->timestampsTz();

            $table->unique(['ticker_id', 'settlement_date']);
            $table->index('settlement_date');
        });

        // SEC CNS fails-to-deliver (bi-monthly zip files, daily rows).
        Schema::create('ftd_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->date('settlement_date');
            $table->unsignedBigInteger('fails_quantity');
            $table->decimal('price', 12, 4)->nullable();
            $table->timestampsTz();

            $table->unique(['ticker_id', 'settlement_date']);
            $table->index('settlement_date');
        });

        // iBorrowDesk (IBKR) borrow fee/availability — forward-only daily.
        Schema::create('borrow_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->decimal('fee', 8, 2)->nullable();       // annualized %
            $table->unsignedBigInteger('available')->nullable();
            $table->timestampsTz();

            $table->unique(['ticker_id', 'day']);
        });

        // LULD / regulatory trade halts (NASDAQ trader RSS, all US venues).
        Schema::create('trade_halts', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 12);
            $table->foreignId('ticker_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('halted_at');
            $table->timestampTz('resumed_at')->nullable();
            $table->string('reason', 10)->nullable(); // NASDAQ halt codes (LUDP, T1...)
            $table->timestampsTz();

            $table->unique(['symbol', 'halted_at']);
            $table->index(['ticker_id', 'halted_at']);
        });

        // Phase F day-0 + squeeze-fuel features on events.
        Schema::table('backtest_events', function (Blueprint $table) {
            // Day-0 microstructure (first 30 minutes of the signal session)
            $table->decimal('or_return_30m', 10, 4)->nullable();  // 10:00 close vs open
            $table->decimal('vwap_dist_30m', 10, 4)->nullable();  // 10:00 price vs session VWAP
            $table->decimal('or_vol_share', 10, 4)->nullable();   // first-30m vol / trailing avg daily vol
            $table->boolean('gap_faded')->nullable();             // gapped up, then lost the prior close
            // Squeeze fuel (point-in-time by publication lag)
            $table->decimal('si_days_to_cover', 10, 2)->nullable();
            $table->decimal('si_pct_change', 10, 4)->nullable();  // vs prior period
            $table->decimal('ftd_log', 8, 4)->nullable();         // log10(1 + max fails, visible window)
            $table->decimal('borrow_fee', 8, 2)->nullable();
            $table->unsignedSmallInteger('halted_5d')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backtest_events', function (Blueprint $table) {
            $table->dropColumn([
                'or_return_30m', 'vwap_dist_30m', 'or_vol_share', 'gap_faded',
                'si_days_to_cover', 'si_pct_change', 'ftd_log', 'borrow_fee', 'halted_5d',
            ]);
        });
        Schema::dropIfExists('trade_halts');
        Schema::dropIfExists('borrow_rates');
        Schema::dropIfExists('ftd_reports');
        Schema::dropIfExists('short_interest');
    }
};
