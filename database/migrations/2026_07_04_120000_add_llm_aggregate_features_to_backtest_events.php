<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backtest_events', function (Blueprint $table) {
            // Phase B/C: per-(ticker, day) aggregates of the LLM post
            // classifications (post type, direction, conviction, pump
            // suspicion, catalyst claims). llm_coverage records what share
            // of the day's mention posts are actually classified — the
            // backfill is incremental, so the model must be able to
            // discount days with thin coverage.
            $table->float('llm_coverage')->nullable()->after('mention_streak');
            $table->float('llm_direction')->nullable()->after('llm_coverage');
            $table->float('llm_conviction')->nullable()->after('llm_direction');
            $table->float('llm_pump_suspicion')->nullable()->after('llm_conviction');
            $table->float('llm_dd_share')->nullable()->after('llm_pump_suspicion');
            $table->float('llm_hype_share')->nullable()->after('llm_dd_share');
            $table->float('llm_news_share')->nullable()->after('llm_hype_share');
            $table->float('llm_catalyst_share')->nullable()->after('llm_news_share');
        });
    }

    public function down(): void
    {
        Schema::table('backtest_events', function (Blueprint $table) {
            $table->dropColumn([
                'llm_coverage', 'llm_direction', 'llm_conviction', 'llm_pump_suspicion',
                'llm_dd_share', 'llm_hype_share', 'llm_news_share', 'llm_catalyst_share',
            ]);
        });
    }
};
