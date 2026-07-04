<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company profile from Polygon ticker details (one row per ticker,
        // refreshed weekly / on page view when stale).
        Schema::create('ticker_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->string('sic_description')->nullable();
            $table->string('homepage_url')->nullable();
            $table->string('primary_exchange')->nullable();
            $table->string('locale', 8)->nullable();
            $table->string('city')->nullable();
            $table->string('state', 32)->nullable();
            $table->unsignedInteger('total_employees')->nullable();
            $table->date('list_date')->nullable();
            $table->unsignedBigInteger('market_cap')->nullable();
            $table->unsignedBigInteger('shares_outstanding')->nullable();
            $table->unsignedBigInteger('weighted_shares_outstanding')->nullable();
            $table->timestampTz('synced_at');
            $table->timestampsTz();
        });

        // Standardized financial statements from Polygon vX financials
        // (SEC XBRL). One row per (ticker, timeframe, period end).
        Schema::create('ticker_financials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->string('timeframe', 12); // quarterly | annual | ttm
            $table->string('fiscal_period', 8)->nullable(); // Q1..Q4 | FY
            $table->string('fiscal_year', 8)->nullable();
            $table->date('end_date');
            $table->date('filing_date')->nullable();
            $table->double('revenue')->nullable();
            $table->double('operating_expenses')->nullable();
            $table->double('net_income')->nullable();
            $table->double('eps_basic')->nullable();
            $table->double('operating_cash_flow')->nullable();
            $table->double('cash')->nullable();
            $table->double('current_assets')->nullable();
            $table->double('current_liabilities')->nullable();
            $table->double('total_assets')->nullable();
            $table->double('total_liabilities')->nullable();
            $table->double('equity')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->unique(['ticker_id', 'timeframe', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticker_financials');
        Schema::dropIfExists('ticker_profiles');
    }
};
