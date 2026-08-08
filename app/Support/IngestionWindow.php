<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The "last complete week" every source is asked for — NASA POWER and most reanalysis
 * sources lag a few days behind real time, so this is always 7-13 days ago, never the
 * current week. Shared between the weekly scheduled ingestion and an on-demand first
 * pull (e.g. when a user activates a previously-dormant region) so both ask for exactly
 * the same period rather than drifting apart.
 */
class IngestionWindow
{
    /**
     * @return array{0: Carbon, 1: Carbon} [periodStart, periodEnd]
     */
    public static function lastComplete(): array
    {
        $periodEnd = Carbon::now()->subDays(6)->startOfDay();
        $periodStart = $periodEnd->copy()->subDays(6);

        return [$periodStart, $periodEnd];
    }
}
