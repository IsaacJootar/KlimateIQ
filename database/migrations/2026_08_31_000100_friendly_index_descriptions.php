<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clarity Pass A2 — rewrite every index description as one plain sentence with a "who it's for"
 * clause, and surface it in the UI (it was written in the seeder and shown nowhere). No admin
 * edit surface for `indices.description`, so a one-time data migration; the seeders carry the
 * same strings for fresh installs.
 */
return new class extends Migration
{
    /** @var array<string, string> code => description */
    private const DESCRIPTIONS = [
        'MALARIA_RISK' => 'Rainfall and standing water turned into a per-LGA malaria risk score — for programme officers pre-positioning nets, tests and treatment before cases climb.',
        'FLOOD_RISK' => 'Rainfall, standing water and terrain combined to rank which LGAs are closest to flooding — for emergency agencies staging shelter and clean water ahead of displacement.',
        'COMPOSITE_PRESSURE' => 'Every active signal, weighted into one overall climate-health pressure score — a cross-cutting snapshot for anyone who needs the big picture before drilling in.',
        'HEAT_STRESS_RISK' => 'Temperature and loss of vegetation cover as a heat-health score — for occupational-safety and public-health teams timing heat advisories.',
        'DROUGHT_RISK' => 'Rainfall shortfall and vegetation stress as a drought score — for agriculture and water-security planners acting before a season visibly fails.',
        'RESPIRATORY_RISK' => 'Fine and coarse particle pollution, ozone, NO₂ and harmattan dust as a respiratory-health score — for air-quality advisories, especially in dust season.',
        'WATERBORNE_DISEASE_RISK' => 'Standing water and rainfall weighted for cholera and typhoid risk after flooding — for WASH teams targeting water treatment and safe-water messaging.',
        'AGRICULTURE_STRESS' => 'Soil moisture, rainfall shortfall and evaporation demand as an early crop water-stress score — for extension officers deciding where to send support before crops visibly wilt.',
        'IRRIGATION_NEED' => 'Evaporation demand against soil moisture and recent rain — where supplementary irrigation would do the most good this week. A targeting score, not a water volume.',
        'RANGELAND_STRESS' => 'Vegetation loss and rainfall shortfall across grazing land — an early signal of pasture failure and the pastoralist movement that can follow it, for agriculture and security planners.',
        'WILDFIRE_RISK' => 'Dry air, dry vegetation, wind and heat as a bush-fire-weather score, with satellite fire detections shown alongside for confirmation — for land management and fire services in the dry season.',
        'DUST_STORM_RISK' => 'Airborne dust, wind and dry air as a harmattan dust-storm score — for air-quality, road-safety and respiratory-health warnings.',
    ];

    public function up(): void
    {
        foreach (self::DESCRIPTIONS as $code => $description) {
            DB::table('indices')->where('code', $code)->update(['description' => $description]);
        }
    }

    public function down(): void
    {
        // Descriptions are cosmetic. No rollback.
    }
};
