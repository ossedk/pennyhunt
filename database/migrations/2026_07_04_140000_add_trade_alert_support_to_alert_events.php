<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // System-generated trade alerts (stop proximity, time-exit tomorrow,
        // filings on open positions, mention collapse) have no user rule.
        Schema::table('alert_events', function (Blueprint $table) {
            $table->foreignId('alert_rule_id')->nullable()->change();
            $table->string('kind')->default('rule')->index();
            $table->foreignId('signal_trade_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alert_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signal_trade_id');
            $table->dropColumn('kind');
        });
    }
};
