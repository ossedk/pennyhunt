<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_sentiments', function (Blueprint $table) {
            // LLM verdict: post is not about the equity (crypto token with
            // the same $symbol, airdrop promo, unrelated topic). Off-topic
            // twitter posts get their ticker mentions removed.
            $table->boolean('llm_off_topic')->nullable()->after('llm_catalyst');
        });
    }

    public function down(): void
    {
        Schema::table('post_sentiments', function (Blueprint $table) {
            $table->dropColumn('llm_off_topic');
        });
    }
};
