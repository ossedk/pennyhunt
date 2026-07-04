<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Simulated exit under stop-loss / take-profit rules. When a run has
        // no exit params these mirror the 5-day time exit (return_5d).
        Schema::table('backtest_events', function (Blueprint $table) {
            $table->float('exit_return')->nullable()->after('best_close_5d');
            $table->string('exit_reason', 8)->nullable()->after('exit_return'); // stop | take | time
            $table->unsignedSmallInteger('exit_day')->nullable()->after('exit_reason'); // trading days held (0 = entry day)
        });
    }

    public function down(): void
    {
        Schema::table('backtest_events', function (Blueprint $table) {
            $table->dropColumn(['exit_return', 'exit_reason', 'exit_day']);
        });
    }
};
