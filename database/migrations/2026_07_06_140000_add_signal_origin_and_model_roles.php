<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How the signal fired: 'composite' (buzz threshold, legacy path)
        // or 'model' (phase F: moonshot head over all candidates).
        Schema::table('signals', function (Blueprint $table) {
            $table->string('origin', 20)->default('composite')->index();
        });

        // Model registry roles: 'confidence' = P(+30%) scorer used for
        // tiers/Kelly, 'moonshot' = P(+75%) head used for model-first fires.
        Schema::table('signal_models', function (Blueprint $table) {
            $table->string('role', 20)->default('confidence')->index();
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
        Schema::table('signal_models', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
