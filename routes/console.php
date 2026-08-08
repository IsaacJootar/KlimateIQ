<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Weekly signal ingestion — queued, so a slow or failing source can't block the others.
Schedule::command('signals:ingest')->weeklyOn(1, '02:00');

// Recalculates every index/region from whatever signals landed above. Runs a couple of hours
// later, not right after — ingestion only enqueues jobs, a queue worker has to actually work
// through all of them (the slowest sources take up to a few minutes each) before there's new
// data worth scoring.
Schedule::command('scores:calculate')->weeklyOn(1, '04:00');
