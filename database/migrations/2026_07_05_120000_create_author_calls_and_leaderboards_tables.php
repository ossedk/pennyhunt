<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per author x ticker x entry date: the author's first
        // non-bearish post opens a "call", priced at the next session open
        // and graded on forward closes — the auditable atom behind the
        // voices leaderboard.
        Schema::create('author_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raw_post_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('called_at');
            $table->date('entry_date')->nullable();
            $table->decimal('entry_price', 14, 4)->nullable();
            $table->decimal('peak_return', 8, 4)->nullable();   // max close vs entry within horizon
            $table->decimal('day5_return', 8, 4)->nullable();   // close at horizon vs entry
            $table->decimal('runup_3d', 8, 4)->nullable();      // pre-call momentum: chaser vs early
            $table->string('outcome')->default('pending');      // pending | win | flat | loss | unpriceable
            $table->timestampTz('graded_at')->nullable();
            $table->timestampsTz();

            $table->index(['author_id', 'ticker_id', 'called_at']);
            $table->index(['outcome']);
            $table->index(['ticker_id', 'called_at']);
        });

        // Weekly snapshot of the ranked leaderboard — cheap indexed read for
        // the page, and history of who is rising/falling.
        Schema::create('author_leaderboards', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');
            $table->foreignId('author_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('rank');
            $table->unsignedInteger('calls');
            $table->unsignedInteger('wins');
            $table->unsignedInteger('flats');
            $table->unsignedInteger('losses');
            $table->decimal('hit_rate', 6, 4);
            $table->decimal('wilson_lb', 6, 4);       // ranking key: 95% Wilson lower bound on win rate
            $table->decimal('avg_peak_return', 8, 4)->nullable();
            $table->decimal('best_peak_return', 8, 4)->nullable();
            $table->jsonb('best_call')->nullable();   // {symbol, peak_return, called_at}
            $table->jsonb('top_tickers')->nullable(); // [{symbol, calls, wins}]
            $table->jsonb('recent_calls')->nullable();
            $table->timestampsTz();

            $table->unique(['week_start', 'author_id']);
            $table->index(['week_start', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('author_leaderboards');
        Schema::dropIfExists('author_calls');
    }
};
