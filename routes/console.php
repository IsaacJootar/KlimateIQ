<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Split cadence, not one weekly run for everything: Rainfall and Standing Water feed Flood
// Risk, which this platform bills as an emergency-response tool — a week-old reading of either
// isn't good enough for that (a flood can develop and recede inside a week). Temperature,
// Vegetation, and Elevation move slowly enough that weekly is genuinely fine for them; polling
// them daily would just be extra load on their APIs for data that hasn't meaningfully changed.
Schedule::command('signals:ingest --source=RAINFALL,STANDING_WATER')->dailyAt('02:00');
Schedule::command('signals:ingest --source=TEMPERATURE,VEGETATION,ELEVATION')->weeklyOn(1, '02:30');

// Recalculates every index/region from whatever signals have landed. Runs daily, not weekly,
// now that some signals do — cheap to rerun even on a day only the slow signals were untouched,
// and it's what actually makes the faster rainfall/standing-water ingestion above meaningful:
// without this also running daily, fresher raw signals would just sit unused until the old
// weekly recalculation caught up to them.
Schedule::command('scores:calculate')->dailyAt('04:00');
