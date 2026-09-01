<?php

use App\Support\IngestionCadence;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cadence matched to how fast each signal actually changes, not grouped by convenience — see
// App\Support\IngestionCadence's docblock for the reasoning per tier. That class is the single
// source of truth, also used to explain a "no data yet" signal in the UI (regions/show.blade.php).
//
// DAILY: Rainfall/Standing Water feed Flood Risk (an emergency-response tool — a week-old
// reading isn't good enough there); Temperature and Air Quality are just as volatile day to day
// and use the same free, unrestricted-volume APIs, so there's no cost reason to hold them back.
Schedule::command('signals:ingest --source='.implode(',', IngestionCadence::DAILY))->dailyAt('02:00');
// WEEKLY: Vegetation's underlying satellite product is itself a 16-day composite — weekly
// already meets its natural update rate, polling more often would mostly return unchanged data.
Schedule::command('signals:ingest --source='.implode(',', IngestionCadence::WEEKLY))->weeklyOn(1, '02:30');
// FORECAST: a fresh GloFAS discharge forecast every day (BUILD_PLAN.md T4). Runs after the
// observed daily pull and before scoring, in its own lane — region_forecast_signals, never
// region_signals — so a forecast can never leak into an observed-data query.
Schedule::command('signals:ingest-forecast')->dailyAt('03:00');
// Elevation and Population aren't scheduled at all — see IngestionCadence::ONCE. They're pulled
// once when a region is first activated and re-pulled only manually if the reference data itself
// changes (a recurring pull for near-static data would just burn quota against rate-limited
// sources like Open Topo Data for no benefit).

// Recalculates every index/region from whatever signals have landed. Runs daily, not weekly,
// now that some signals do — cheap to rerun even on a day only the slow signals were untouched,
// and it's what actually makes the faster rainfall/standing-water ingestion above meaningful:
// without this also running daily, fresher raw signals would just sit unused until the old
// weekly recalculation caught up to them.
Schedule::command('scores:calculate')->dailyAt('04:00');
// Forecast indices score in their own lane, from region_forecast_signals, right after the
// observed pass — see BUILD_PLAN.md T4.
Schedule::command('scores:forecast')->dailyAt('04:15');
// Riverine Flood Forecast needs per-LGA discharge bounds or every big-river reach pegs at 100.
// Weekly is plenty — the observed baseline moves slowly, and it no-ops until there's history.
Schedule::command('calibrate:river-discharge')->weeklyOn(1, '03:45');
