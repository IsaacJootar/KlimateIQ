<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T4/T5 follow-up — reach-level riverine forecast. A confluence/valley LGA sits on more than one
 * river; a single GloFAS sample at the LGA centroid can't tell a Niger flood from a Benue flood.
 *
 *   river_reaches                       — the curated sample points (one per LGA per river reach)
 *   region_forecast_signals.reach       — which reach a discharge row belongs to ('centroid' =
 *                                         the single-point behaviour, and every non-discharge
 *                                         forecast signal)
 *   scoring_calibration_parameters.reach — per-reach flood thresholds (null = the LGA-wide bound)
 *
 * All additive: an LGA with no river_reaches rows keeps scoring exactly as before, at 'centroid'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('river_reaches', function (Blueprint $table) {
            $table->bigIncrements('river_reach_id');
            $table->unsignedInteger('region_id');
            $table->string('reach', 40);
            $table->string('river', 60);
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->string('source', 500);
            $table->timestamps();
            $table->foreign('region_id')->references('region_id')->on('regions')->cascadeOnDelete();
            $table->unique(['region_id', 'reach']);
        });

        Schema::table('region_forecast_signals', function (Blueprint $table) {
            $table->string('reach', 40)->default('centroid')->after('member');
            $table->dropUnique('region_forecast_signals_unique_target');
            $table->unique(
                ['region_id', 'signal_type_id', 'target_date', 'member', 'reach'],
                'region_forecast_signals_unique_target',
            );
        });

        Schema::table('scoring_calibration_parameters', function (Blueprint $table) {
            $table->string('reach', 40)->nullable()->after('region_id');
            $table->dropUnique('scoring_calibration_parameters_unique_key');
            $table->unique(['index_id', 'region_id', 'reach', 'parameter_key'], 'scoring_calibration_parameters_unique_key');
        });
    }

    public function down(): void
    {
        Schema::table('scoring_calibration_parameters', function (Blueprint $table) {
            $table->dropUnique('scoring_calibration_parameters_unique_key');
            $table->unique(['index_id', 'region_id', 'parameter_key'], 'scoring_calibration_parameters_unique_key');
            $table->dropColumn('reach');
        });

        Schema::table('region_forecast_signals', function (Blueprint $table) {
            $table->dropUnique('region_forecast_signals_unique_target');
            $table->unique(
                ['region_id', 'signal_type_id', 'target_date', 'member'],
                'region_forecast_signals_unique_target',
            );
            $table->dropColumn('reach');
        });

        Schema::dropIfExists('river_reaches');
    }
};
