<?php

namespace Tests\Unit;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Regression coverage for the cadence split: Rainfall/Standing Water (Flood Risk relevant)
 * daily, everything else weekly, scores recalculated daily. Asserts against the actual
 * registered Schedule, not just that routes/console.php doesn't throw, so a future edit that
 * accidentally reverts to one weekly command for everything gets caught.
 */
class ScheduledIngestionCadenceTest extends TestCase
{
    public function test_fast_moving_signals_are_ingested_daily(): void
    {
        $event = $this->findEvent('signals:ingest --source=RAINFALL,STANDING_WATER');

        $this->assertSame('0 2 * * *', $event->expression);
    }

    public function test_slow_moving_signals_stay_weekly(): void
    {
        $event = $this->findEvent('signals:ingest --source=TEMPERATURE,VEGETATION,ELEVATION');

        $this->assertSame('30 2 * * 1', $event->expression);
    }

    public function test_score_calculation_runs_daily_not_weekly(): void
    {
        $event = $this->findEvent('scores:calculate');

        $this->assertSame('0 4 * * *', $event->expression);
    }

    private function findEvent(string $commandContains): \Illuminate\Console\Scheduling\Event
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())->first(
            fn ($e) => str_contains($e->command ?? '', $commandContains)
        );

        $this->assertNotNull($event, "No scheduled event found containing \"{$commandContains}\".");

        return $event;
    }
}
