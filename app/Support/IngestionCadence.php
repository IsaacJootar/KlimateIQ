<?php

namespace App\Support;

/**
 * Which signals refresh daily versus weekly — see routes/console.php for why the split exists.
 * The single source of truth for both the scheduler and anywhere the UI needs to explain why a
 * signal has no reading yet (e.g. "pending this week's update" vs. a genuine gap).
 */
class IngestionCadence
{
    public const DAILY = ['RAINFALL', 'STANDING_WATER'];

    public const WEEKLY = ['TEMPERATURE', 'VEGETATION', 'ELEVATION', 'POPULATION_EXPOSURE', 'AIR_QUALITY_PM25', 'AIR_QUALITY_PM10'];

    public static function isWeekly(string $signalTypeCode): bool
    {
        return in_array($signalTypeCode, self::WEEKLY, true);
    }
}
