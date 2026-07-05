<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('author_leaderboards', function (Blueprint $table) {
            // One board per platform (reddit / twitter), ranked independently.
            $table->string('platform', 20)->default('reddit');
            $table->index(['week_start', 'platform', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::table('author_leaderboards', function (Blueprint $table) {
            $table->dropIndex(['week_start', 'platform', 'rank']);
            $table->dropColumn('platform');
        });
    }
};
