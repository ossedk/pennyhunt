<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SIC industry code from EDGAR submissions — sector bucket for the
        // sympathy-play (sector heat) features. 2-digit prefix = major group.
        Schema::table('tickers', function (Blueprint $table) {
            $table->string('sic_code', 4)->nullable()->index();
        });

        // Form 4 insider transactions (open-market P/S codes only).
        Schema::create('insider_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->string('accession', 30);
            $table->unsignedSmallInteger('seq'); // transaction index within the filing
            $table->date('filed_at');
            $table->date('transacted_at')->nullable();
            $table->string('owner_name')->nullable();
            $table->boolean('is_officer')->default(false);
            $table->boolean('is_director')->default(false);
            $table->string('code', 2); // P = purchase, S = sale
            $table->decimal('shares', 16, 2)->nullable();
            $table->decimal('price', 12, 4)->nullable();
            $table->decimal('value', 16, 2)->nullable(); // shares * price
            $table->timestampsTz();

            $table->unique(['accession', 'seq']);
            $table->index(['ticker_id', 'filed_at']);
        });

        // LLM catalyst classification on news headlines.
        Schema::table('ticker_news', function (Blueprint $table) {
            $table->string('catalyst_type', 30)->nullable()->index();
            $table->timestampTz('catalyst_classified_at')->nullable();
        });

        // New model features on backtest events (as-of, no look-ahead).
        Schema::table('backtest_events', function (Blueprint $table) {
            // Technical (from daily bars)
            $table->decimal('rvol', 10, 4)->nullable();
            $table->decimal('atr_pct', 10, 4)->nullable();
            $table->decimal('range_expansion', 10, 4)->nullable();
            $table->decimal('dist_52w_high', 10, 4)->nullable();
            $table->unsignedSmallInteger('up_streak')->nullable();
            $table->decimal('gap_open', 10, 4)->nullable();
            // Sector sympathy
            $table->decimal('sector_heat', 8, 4)->nullable();
            $table->decimal('sector_mention_z', 8, 2)->nullable();
            // Macro regime
            $table->decimal('smallcap_rel_20d', 10, 4)->nullable();
            $table->decimal('xbi_ret_5d', 10, 4)->nullable();
            // Insider flow
            $table->unsignedSmallInteger('insider_buys_90d')->nullable();
            $table->decimal('insider_net_value_90d', 10, 4)->nullable();
            // News catalysts
            $table->boolean('news_catalyst_7d')->nullable();
            $table->boolean('news_offering_7d')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backtest_events', function (Blueprint $table) {
            $table->dropColumn([
                'rvol', 'atr_pct', 'range_expansion', 'dist_52w_high', 'up_streak', 'gap_open',
                'sector_heat', 'sector_mention_z', 'smallcap_rel_20d', 'xbi_ret_5d',
                'insider_buys_90d', 'insider_net_value_90d', 'news_catalyst_7d', 'news_offering_7d',
            ]);
        });
        Schema::table('ticker_news', function (Blueprint $table) {
            $table->dropColumn(['catalyst_type', 'catalyst_classified_at']);
        });
        Schema::dropIfExists('insider_trades');
        Schema::table('tickers', function (Blueprint $table) {
            $table->dropColumn('sic_code');
        });
    }
};
