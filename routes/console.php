<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Weekly signal ingestion — queued, so a slow or failing source can't block the others.
Schedule::command('signals:ingest')->weeklyOn(1, '02:00');
