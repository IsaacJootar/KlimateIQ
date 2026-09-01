<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUILD_PLAN.md T4 — marks an index as forward-looking. A forecast index has no observed
 * `region_scores` row; it is owned by `scores:forecast` and read from `region_forecast_scores`.
 * `scores:calculate` skips these so it never tries to score a completed period from signals
 * that only exist as a forecast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indices', function (Blueprint $table) {
            $table->boolean('is_forecast')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('indices', function (Blueprint $table) {
            $table->dropColumn('is_forecast');
        });
    }
};
