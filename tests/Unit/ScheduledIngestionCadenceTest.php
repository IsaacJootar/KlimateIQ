<?php

namespace Tests\Unit;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Regression coverage for the cadence split — see App\Support\IngestionCadence's docblock for
 * why each signal sits where it does. Asserts against the actual registered Schedule, not just
 * that routes/console.php doesn't throw, so a future edit that accidentally reverts to one
 * weekly command for everything (or drops a signal from any tier) gets caught.
 */
class ScheduledIngestionCadenceTest extends TestCase
{
    public function test_volatile_signals_are_ingested_daily(): void
    {
        $event = $this->findEvent('signals:ingest --source=RAINFALL,STANDING_WATER,TEMPERATURE,AIR_QUALITY_PM25,AIR_QUALITY_PM10,SOIL_MOISTURE,EVAPOTRANSPIRATION');

        $this->assertSame('0 2 * * *', $event->expression);
    }

    public function test_vegetation_stays_weekly(): void
    {
        $event = $this->findEvent('signals:ingest --source=VEGETATION');

        $this->assertSame('30 2 * * 1', $event->expression);
    }

    public function test_elevation_and_population_have_no_recurring_schedule(): void
    {
        $schedule = app(Schedule::class);

        $scheduledElevation = collect($schedule->events())->first(
            fn ($e) => str_contains($e->command ?? '', 'ELEVATION') || str_contains($e->command ?? '', 'POPULATION_EXPOSURE')
        );

        $this->assertNull($scheduledElevation, 'Elevation/Population should only be pulled on region activation or manually, never on a recurring schedule.');
    }

    public function test_score_calculation_runs_daily_not_weekly(): void
    {
        $event = $this->findEvent('scores:calculate');

        $this->assertSame('0 4 * * *', $event->expression);
    }

    private function findEvent(string $commandContains): Event
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())->first(
            fn ($e) => str_contains($e->command ?? '', $commandContains)
        );

        $this->assertNotNull($event, "No scheduled event found containing \"{$commandContains}\".");

        return $event;
    }
}
