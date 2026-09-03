<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUILD_PLAN.md T5 — probabilistic scoring. The forecast signal lane now carries an *ensemble*:
 * the same forecast day re-run many times from perturbed initial conditions. Each row gets a
 * `member` tag so the deterministic (control) series and the ensemble members share one table
 * without a second lane.
 *
 *   'control'   — the single deterministic run T4 already writes (unchanged behaviour)
 *   'glofas-NN' — a GloFAS ensemble member (Open-Meteo Flood API, &ensemble=true)
 *   'gfs-NN' / 'ecmwf-NN' / 'icon-NN' — a weather-model ensemble member (Open-Meteo Ensemble API)
 *
 * The unique key gains `member` so members coexist; existing rows default to 'control', so this
 * is column-add only, no data migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('region_forecast_signals', function (Blueprint $table) {
            $table->string('member', 20)->default('control')->after('signal_type_id');
            $table->dropUnique('region_forecast_signals_unique_target');
            $table->unique(
                ['region_id', 'signal_type_id', 'target_date', 'member'],
                'region_forecast_signals_unique_target',
            );
        });
    }

    public function down(): void
    {
        Schema::table('region_forecast_signals', function (Blueprint $table) {
            $table->dropUnique('region_forecast_signals_unique_target');
            $table->unique(
                ['region_id', 'signal_type_id', 'target_date'],
                'region_forecast_signals_unique_target',
            );
            $table->dropColumn('member');
        });
    }
};
