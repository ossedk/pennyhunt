<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            // LLM-written "what to look for" note: {summary, watch_for[],
            // invalidation, risk}. Generated on fire, lazily backfilled on view.
            $table->jsonb('llm_brief')->nullable();
            $table->timestampTz('llm_brief_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropColumn(['llm_brief', 'llm_brief_at']);
        });
    }
};
