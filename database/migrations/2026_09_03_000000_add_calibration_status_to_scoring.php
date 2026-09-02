<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records how trustworthy each scoring weight and calibration bound is (App\Support\CalibrationStatus),
 * so it's structured data an admin can see and a test can enforce — not prose in docs/MODEL.md
 * that drifts. Everything already on the platform is a placeholder except the PM2.5 / PM10
 * bounds, which cite a public-health reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scoring_calibration_parameters', function (Blueprint $table) {
            $table->string('calibration_status', 24)->default('placeholder')->after('source_reference');
        });

        Schema::table('region_scoring_configs', function (Blueprint $table) {
            $table->string('calibration_status', 24)->default('placeholder')->after('enabled');
        });

        // Bounds already backed by a cited public reference (US EPA AQI, WHO air-quality points).
        DB::table('scoring_calibration_parameters')
            ->where(function ($q) {
                foreach (['AIR_QUALITY_PM', 'OZONE_', 'NO2_', 'DUST_'] as $prefix) {
                    $q->orWhere('parameter_key', 'like', "{$prefix}%");
                }
            })
            ->update(['calibration_status' => 'reference']);
    }

    public function down(): void
    {
        Schema::table('scoring_calibration_parameters', fn (Blueprint $table) => $table->dropColumn('calibration_status'));
        Schema::table('region_scoring_configs', fn (Blueprint $table) => $table->dropColumn('calibration_status'));
    }
};
