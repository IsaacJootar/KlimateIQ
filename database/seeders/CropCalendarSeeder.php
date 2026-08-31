<?php

namespace Database\Seeders;

use App\Models\CropCalendar;
use App\Support\AgroZone;
use Illuminate\Database\Seeder;

/**
 * Clarity Pass D2 — the water-sensitive growth window for Nigeria's principal rain-fed crops,
 * by agro-ecological zone. From public crop calendars (FAO / FEWS NET Nigeria); the months are
 * the reproductive / yield-forming phase (flowering, grain-fill, tuber bulking) when a rainfall
 * shortfall does the most damage.
 *
 * Idempotent (updateOrCreate on scope + scope_key + crop). Kept out of the deploy-time seeders'
 * critical path only in the sense that it needs the crop_calendar table — the migration creates
 * it first.
 */
class CropCalendarSeeder extends Seeder
{
    public function run(): void
    {
        // zone => [crop, water-sensitive stage, [months that stage falls in]]
        $calendar = [
            AgroZone::SAHEL => [
                ['Millet', 'grain-fill', [8, 9]],
                ['Sorghum', 'flowering', [9, 10]],
                ['Cowpea', 'pod-fill', [9]],
            ],
            AgroZone::SUDAN_SAVANNA => [
                ['Millet', 'grain-fill', [8, 9]],
                ['Sorghum', 'grain-fill', [9, 10]],
                ['Maize', 'grain-fill', [8, 9]],
                ['Cowpea', 'pod-fill', [9, 10]],
                ['Groundnut', 'pod-fill', [9]],
            ],
            AgroZone::NORTHERN_GUINEA => [
                ['Maize', 'grain-fill', [8, 9]],
                ['Sorghum', 'grain-fill', [9, 10]],
                ['Rice', 'flowering', [9, 10]],
                ['Soybean', 'pod-fill', [9, 10]],
                ['Yam', 'tuber bulking', [8, 9, 10]],
            ],
            AgroZone::SOUTHERN_GUINEA => [
                ['Maize', 'grain-fill', [6, 7]],
                ['Yam', 'tuber bulking', [8, 9, 10]],
                ['Rice', 'flowering', [9, 10]],
                ['Sorghum', 'grain-fill', [9, 10]],
                ['Sesame', 'pod-fill', [10, 11]],
            ],
            AgroZone::DERIVED_SAVANNA => [
                ['Maize', 'grain-fill', [6, 7, 10]],
                ['Cassava', 'root bulking', [7, 8, 9, 10]],
                ['Yam', 'tuber bulking', [8, 9, 10]],
                ['Cowpea', 'pod-fill', [7, 10]],
                ['Rice', 'flowering', [9, 10]],
            ],
            AgroZone::HUMID_FOREST => [
                ['Maize', 'grain-fill', [6, 7, 10, 11]],
                ['Cassava', 'root bulking', [7, 8, 9, 10]],
                ['Yam', 'tuber bulking', [8, 9, 10, 11]],
                ['Rice', 'flowering', [9, 10]],
                ['Plantain', 'dry-season water stress', [12, 1, 2]],
            ],
        ];

        foreach ($calendar as $zone => $crops) {
            foreach ($crops as $order => [$crop, $stage, $months]) {
                CropCalendar::query()->updateOrCreate(
                    ['scope' => 'zone', 'scope_key' => $zone, 'crop' => $crop],
                    ['stage' => $stage, 'sensitive_months' => $months, 'sort_order' => $order],
                );
            }
        }
    }
}
