<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clarity Pass A1 — rename signal types from the terse / stale labels to what the person
 * reading a dashboard actually calls them. `signal_types.name` has no admin edit surface, so a
 * one-time data migration is the right tool; the seeders carry the same values for fresh installs.
 */
return new class extends Migration
{
    /** @var array<string, string> code => friendly name */
    private const NAMES = [
        'RAINFALL' => 'Rainfall',
        'STANDING_WATER' => 'Standing Water',
        'TEMPERATURE' => 'Temperature',
        'VEGETATION' => 'Vegetation Cover',
        'POPULATION_EXPOSURE' => 'Population Exposure',
        'ELEVATION' => 'Elevation',
        'AIR_QUALITY_PM25' => 'Fine Particle Pollution (PM2.5)',
        'AIR_QUALITY_PM10' => 'Coarse Particle Pollution (PM10)',
        'SOIL_MOISTURE' => 'Soil Moisture (Root Zone)',
        'EVAPOTRANSPIRATION' => 'Evaporation Demand (ET₀)',
        'HUMIDITY' => 'Air Humidity',
        'WIND_SPEED' => 'Wind Speed',
        'DUST' => 'Airborne Dust',
        'ACTIVE_FIRE' => 'Active Fire Detections',
        'OZONE' => 'Ground-Level Ozone',
        'NO2' => 'Nitrogen Dioxide',
    ];

    public function up(): void
    {
        foreach (self::NAMES as $code => $name) {
            DB::table('signal_types')->where('code', $code)->update(['name' => $name]);
        }
    }

    public function down(): void
    {
        // Names are cosmetic — nothing keys on them. No rollback.
    }
};
