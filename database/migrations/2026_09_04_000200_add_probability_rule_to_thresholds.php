<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUILD_PLAN.md T5 M3 — the probability threshold rule. A config with alert_type
 * 'forecast_probability' fires when P(index peak >= threshold_value within the horizon) reaches
 * probability_threshold percent, read off the ensemble distribution. The realised probability
 * at trigger is stored on the alert for the "≈72% chance" notification line.
 *
 * alert_type is a plain string column — no enum to alter, the new value is accepted once the
 * controller validation and ThresholdConfig::isProbabilityType() know about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threshold_configs', function (Blueprint $table) {
            $table->decimal('probability_threshold', 5, 2)->nullable()->after('anomaly_stddev_multiplier');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->decimal('forecast_probability', 5, 4)->nullable()->after('forecast_lead_days');
        });
    }

    public function down(): void
    {
        Schema::table('threshold_configs', fn (Blueprint $table) => $table->dropColumn('probability_threshold'));
        Schema::table('alerts', fn (Blueprint $table) => $table->dropColumn('forecast_probability'));
    }
};
