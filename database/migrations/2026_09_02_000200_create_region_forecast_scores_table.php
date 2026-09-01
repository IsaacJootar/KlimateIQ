<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUILD_PLAN.md T4 — the forward index score. Separate table from `region_scores`, same
 * discipline: composite key, writes via DB upsert not Eloquent save.
 *
 * One row per (index, region): the current forecast. `score` is the PEAK daily risk within the
 * horizon window, with `peak_date` / `lead_days_to_peak` saying when it lands — that is what an
 * emergency planner acts on ("red in 5 days"), not an average. Issuance history is T5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('region_forecast_scores', function (Blueprint $table) {
            $table->unsignedSmallInteger('index_id');
            $table->unsignedInteger('region_id');
            $table->date('forecast_issued_at');
            $table->unsignedSmallInteger('horizon_days');
            $table->decimal('score', 5, 2)->check('score >= 0 AND score <= 100');
            $table->date('peak_date');
            $table->unsignedSmallInteger('lead_days_to_peak');
            $table->string('scoring_strategy', 30)->default('forecast_formula');
            $table->string('scoring_version', 30)->nullable();
            $table->json('breakdown')->nullable();
            $table->timestamp('calculated_at')->useCurrent();
            $table->primary(['index_id', 'region_id']);
            $table->foreign('index_id')->references('index_id')->on('indices')->cascadeOnDelete();
            $table->foreign('region_id')->references('region_id')->on('regions')->cascadeOnDelete();
            $table->index('region_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('region_forecast_scores');
    }
};
