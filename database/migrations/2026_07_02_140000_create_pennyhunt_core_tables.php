<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g. reddit:wallstreetbets, apewisdom, tradestie
            $table->string('type'); // reddit | aggregator | x
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('poll_interval_seconds')->default(120);
            $table->jsonb('config')->nullable();
            $table->timestampTz('last_polled_at')->nullable();
            $table->timestampTz('last_ok_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestampsTz();
        });

        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('platform'); // reddit | x
            $table->string('username');
            $table->timestampTz('account_created_at')->nullable();
            $table->bigInteger('karma')->nullable();
            // 0 (organic) .. 1 (near-certain pump account); recomputed nightly
            $table->float('pump_risk_score')->default(0);
            $table->float('track_record_score')->nullable();
            $table->jsonb('stats')->nullable();
            $table->timestampsTz();

            $table->unique(['platform', 'username']);
        });

        Schema::create('raw_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('kind'); // post | comment
            $table->foreignId('author_id')->nullable()->constrained('authors')->nullOnDelete();
            $table->text('title')->nullable();
            $table->text('body')->nullable();
            $table->string('permalink', 2048)->nullable();
            $table->integer('score')->default(0);
            $table->integer('num_comments')->default(0);
            $table->timestampTz('posted_at');
            $table->timestampTz('ingested_at');
            $table->boolean('mentions_extracted')->default(false);
            $table->boolean('sentiment_scored')->default(false);
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();

            $table->unique(['source_id', 'external_id']);
            $table->index('posted_at');
            $table->index(['mentions_extracted', 'id']);
            $table->index(['sentiment_scored', 'id']);
        });

        Schema::create('tickers', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->unique();
            $table->string('name')->nullable();
            $table->string('exchange')->nullable(); // Nasdaq | NYSE | OTC | ...
            $table->string('tier')->nullable(); // listed | otc-pink | ...
            $table->bigInteger('market_cap')->nullable();
            $table->decimal('last_price', 16, 6)->nullable();
            $table->boolean('is_active')->default(true);
            // Symbols that collide with common English words (CEO, DD, ALL...)
            $table->boolean('is_ambiguous')->default(false);
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();
        });

        Schema::create('post_ticker_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->float('confidence'); // 1.0 cashtag, ~0.7 validated symbol, ~0.4 name match
            $table->string('method'); // cashtag | symbol | name
            $table->timestampTz('posted_at'); // denormalized for fast rollups
            $table->timestampsTz();

            $table->unique(['raw_post_id', 'ticker_id']);
            $table->index(['ticker_id', 'posted_at']);
        });

        Schema::create('post_sentiments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_post_id')->unique()->constrained()->cascadeOnDelete();
            // Tier 0: lexicon (full coverage baseline)
            $table->float('lexicon_score')->nullable(); // -1 .. 1
            // Tier 1: FinBERT triage (via NLP sidecar, phase 2b)
            $table->string('finbert_label')->nullable(); // positive | negative | neutral
            $table->float('finbert_score')->nullable();
            // Tier 2: LLM escalation (non-neutral only)
            $table->string('llm_direction')->nullable(); // bullish | bearish | neutral
            $table->float('llm_conviction')->nullable(); // 0 .. 1
            $table->float('llm_pump_suspicion')->nullable(); // 0 .. 1
            $table->text('llm_reasoning')->nullable();
            $table->timestampTz('scored_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('ticker_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->string('interval'); // 5m | 1h | 1d
            $table->timestampTz('bucket_start');
            $table->unsignedInteger('mention_count')->default(0);
            $table->unsignedInteger('unique_authors')->default(0);
            $table->float('avg_sentiment')->nullable();
            $table->float('weighted_sentiment')->nullable(); // author-quality weighted
            $table->float('author_quality_avg')->nullable();
            // vs this ticker's own trailing baseline
            $table->float('zscore_mentions')->nullable();
            $table->float('zscore_sentiment')->nullable();
            $table->timestampsTz();

            $table->unique(['ticker_id', 'interval', 'bucket_start']);
            $table->index(['interval', 'bucket_start']);
        });

        Schema::create('aggregator_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('symbol');
            $table->unsignedInteger('rank')->nullable();
            $table->unsignedInteger('mentions')->nullable();
            $table->unsignedInteger('upvotes')->nullable();
            $table->unsignedInteger('mentions_24h_ago')->nullable();
            $table->unsignedInteger('rank_24h_ago')->nullable();
            $table->float('sentiment_score')->nullable(); // provider-supplied, if any
            $table->string('sentiment_label')->nullable();
            $table->timestampTz('captured_at');
            $table->timestampsTz();

            $table->index(['symbol', 'captured_at']);
            $table->index(['source_id', 'captured_at']);
        });

        Schema::create('market_bars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->string('interval'); // 5m | 1d
            $table->timestampTz('bucket_start');
            $table->decimal('open', 16, 6);
            $table->decimal('high', 16, 6);
            $table->decimal('low', 16, 6);
            $table->decimal('close', 16, 6);
            $table->bigInteger('volume');
            $table->timestampsTz();

            $table->unique(['ticker_id', 'interval', 'bucket_start']);
        });

        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('fired_at');
            $table->float('composite_score');
            $table->jsonb('breakdown'); // per-component scores + inputs
            $table->string('state')->default('new'); // new | confirmed | expired
            // Auto-grading with realized forward returns (self-evaluation)
            $table->float('forward_return_1d')->nullable();
            $table->float('forward_return_3d')->nullable();
            $table->float('forward_return_5d')->nullable();
            $table->timestampTz('graded_at')->nullable();
            $table->timestampsTz();

            $table->index(['ticker_id', 'fired_at']);
            $table->index('fired_at');
        });

        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestampsTz();
        });

        Schema::create('watchlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watchlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['watchlist_id', 'ticker_id']);
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticker_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind'); // composite_threshold | ticker_signal | mention_spike
            $table->jsonb('params');
            $table->string('channel')->default('in_app'); // in_app | mail
            $table->boolean('enabled')->default(true);
            $table->timestampTz('last_triggered_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signal_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('payload');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('watchlist_items');
        Schema::dropIfExists('watchlists');
        Schema::dropIfExists('signals');
        Schema::dropIfExists('market_bars');
        Schema::dropIfExists('aggregator_snapshots');
        Schema::dropIfExists('ticker_metrics');
        Schema::dropIfExists('post_sentiments');
        Schema::dropIfExists('post_ticker_mentions');
        Schema::dropIfExists('tickers');
        Schema::dropIfExists('raw_posts');
        Schema::dropIfExists('authors');
        Schema::dropIfExists('sources');
    }
};
