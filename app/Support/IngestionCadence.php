<?php

namespace App\Support;

/**
 * Which signals refresh daily, weekly, or once — see routes/console.php for why the split
 * exists. The single source of truth for both the scheduler and anywhere the UI needs to
 * explain why a signal has no reading yet (e.g. "pending this week's update" vs. a genuine gap).
 *
 * Grouped by how fast the real-world thing actually changes, not by convenience:
 *   - DAILY: genuinely volatile day to day, and feeds an index where freshness matters
 *     (rainfall/standing water for Flood+Malaria, temperature for Heat Stress, air quality for
 *     Respiratory — a dust event can spike and clear inside a week, a weekly check could miss it
 *     entirely; soil moisture and ET₀ for Agriculture Stress, where a drying trend needs to be
 *     caught while there's still time to act on it; humidity, wind and dust for Wildfire and
 *     Dust Storm Risk — a harmattan dust event or a fire-weather window opens and closes fast).
 *   - WEEKLY: Vegetation's underlying satellite product (MOD13Q1) is itself a 16-day composite —
 *     weekly polling already meets or beats its natural update rate.
 *   - ONCE: Elevation (terrain doesn't move) and Population (a yearly-at-best census figure).
 *     Pulled once when a region is first activated (see CoveragePreferenceController::
 *     triggerFirstIngestion) and never re-pulled automatically — a recurring schedule for data
 *     that structurally can't change would just be wasted API calls against sources with real
 *     rate limits (see Admin\PipelineHealthController's capacity section). Re-pull manually via
 *     signals:ingest --source=ELEVATION or population:import if the reference data itself changes.
 */
class IngestionCadence
{
    public const DAILY = ['RAINFALL', 'STANDING_WATER', 'TEMPERATURE', 'AIR_QUALITY_PM25', 'AIR_QUALITY_PM10', 'SOIL_MOISTURE', 'EVAPOTRANSPIRATION', 'HUMIDITY', 'WIND_SPEED', 'DUST', 'ACTIVE_FIRE'];

    public const WEEKLY = ['VEGETATION'];

    public const ONCE = ['ELEVATION', 'POPULATION_EXPOSURE'];

    public static function isWeekly(string $signalTypeCode): bool
    {
        return in_array($signalTypeCode, self::WEEKLY, true);
    }
}
