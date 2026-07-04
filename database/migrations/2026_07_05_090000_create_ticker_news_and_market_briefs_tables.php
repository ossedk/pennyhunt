<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticker_news', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            // Polygon's stable article id — dedupe key across syncs.
            $table->string('external_id')->unique();
            $table->string('publisher')->nullable();
            $table->text('title');
            $table->text('article_url');
            $table->text('image_url')->nullable();
            $table->text('description')->nullable();
            $table->timestampTz('published_at');
            $table->timestampsTz();

            $table->index(['ticker_id', 'published_at']);
        });

        Schema::create('market_briefs', function (Blueprint $table): void {
            $table->id();
            $table->string('model');
            // Structured output: headline, body paragraphs, watch items
            // (symbol-bound), risk flags — validated before storage.
            $table->jsonb('brief');
            // The closed-world context the LLM saw (audit trail).
            $table->jsonb('context');
            $table->timestampTz('generated_at');
            $table->timestampsTz();

            $table->index('generated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_briefs');
        Schema::dropIfExists('ticker_news');
    }
};
