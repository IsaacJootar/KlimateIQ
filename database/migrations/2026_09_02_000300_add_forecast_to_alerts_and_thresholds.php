<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUILD_PLAN.md T4 M4 — forecast-breach alerts. A threshold on a forecast index fires on the
 * forecast peak; `watch_forecast` is the forward-compatible opt-in for the day observed indices
 * also get forward-scored. The alert carries when the breach is projected for, so the
 * notification can say "in about 5 days" and clearly label itself a forecast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threshold_configs', function (Blueprint $table) {
            $table->boolean('watch_forecast')->default(false)->after('active');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->boolean('is_forecast')->default(false)->after('status');
            $table->date('forecast_target_date')->nullable()->after('is_forecast');
            $table->unsignedSmallInteger('forecast_lead_days')->nullable()->after('forecast_target_date');
        });
    }

    public function down(): void
    {
        Schema::table('threshold_configs', fn (Blueprint $table) => $table->dropColumn('watch_forecast'));
        Schema::table('alerts', fn (Blueprint $table) => $table->dropColumn(['is_forecast', 'forecast_target_date', 'forecast_lead_days']));
    }
};
