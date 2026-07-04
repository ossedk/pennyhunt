<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SEC CIK for EDGAR lookups (company_tickers.json carries it).
        Schema::table('tickers', function (Blueprint $table) {
            $table->unsignedBigInteger('cik')->nullable()->after('symbol')->index();
        });

        // Point-in-time SEC filing history per ticker. Only forms relevant to
        // dilution/solvency are stored (S-1/S-3/F-3 shelves, 424B takedowns,
        // 8-K, 10-K/Q). filed_at makes every feature computable as-of any
        // backtest day with no look-ahead.
        Schema::create('sec_filings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->string('form', 12);
            $table->date('filed_at');
            $table->string('accession', 25);
            $table->timestampTz('created_at')->nullable();

            $table->unique(['ticker_id', 'accession']);
            $table->index(['ticker_id', 'form', 'filed_at']);
        });

        // Shares-outstanding observations from EDGAR XBRL (dei facts) —
        // 12-month share growth is the cleanest realized-dilution measure.
        Schema::create('ticker_share_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->date('as_of');
            $table->unsignedBigInteger('shares');
            $table->timestampTz('created_at')->nullable();

            $table->unique(['ticker_id', 'as_of']);
        });

        // FINRA Reg SHO daily short-sale volume (off-exchange). short_ratio =
        // short_volume / total_volume for that day's reported flow.
        Schema::create('short_volumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->double('short_volume');
            $table->double('total_volume');
            $table->float('short_ratio');
            $table->timestampTz('created_at')->nullable();

            $table->unique(['ticker_id', 'day']);
            $table->index('day');
        });

        // New as-of features on backtest events (phase A of the signal-quality plan).
        Schema::table('backtest_events', function (Blueprint $table) {
            $table->float('short_ratio')->nullable()->after('pre_return_3d');
            $table->boolean('atm_filed_90d')->nullable()->after('short_ratio');
            $table->boolean('active_shelf')->nullable()->after('atm_filed_90d');
            $table->float('share_growth_12m')->nullable()->after('active_shelf');
            $table->float('market_ret_5d')->nullable()->after('share_growth_12m');
            $table->float('site_mention_z')->nullable()->after('market_ret_5d');
        });

        // LLM post classification (phase B): post *type* carries signal where
        // raw polarity does not (FA-posts bullish, TA/hype-posts bearish).
        Schema::table('post_sentiments', function (Blueprint $table) {
            $table->string('llm_post_type', 16)->nullable()->after('llm_pump_suspicion'); // dd|technical|hype|news|question|other
            $table->boolean('llm_catalyst')->nullable()->after('llm_post_type');
        });

        // Sample size behind authors.track_record_score.
        Schema::table('authors', function (Blueprint $table) {
            $table->unsignedInteger('track_record_n')->nullable()->after('track_record_score');
        });
    }

    public function down(): void
    {
        Schema::table('authors', fn (Blueprint $table) => $table->dropColumn('track_record_n'));
        Schema::table('post_sentiments', fn (Blueprint $table) => $table->dropColumn(['llm_post_type', 'llm_catalyst']));
        Schema::table('backtest_events', fn (Blueprint $table) => $table->dropColumn([
            'short_ratio', 'atm_filed_90d', 'active_shelf', 'share_growth_12m', 'market_ret_5d', 'site_mention_z',
        ]));
        Schema::dropIfExists('short_volumes');
        Schema::dropIfExists('ticker_share_counts');
        Schema::dropIfExists('sec_filings');
        Schema::table('tickers', fn (Blueprint $table) => $table->dropColumn('cik'));
    }
};
