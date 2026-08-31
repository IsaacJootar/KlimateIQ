<?php

namespace Database\Seeders;

use App\Models\CropCalendar;
use App\Support\AgroZone;
use Illuminate\Database\Seeder;

/**
 * Clarity Pass D2 — the water-sensitive growth window for Nigeria's principal rain-fed crops,
 * by agro-ecological zone. From public crop calendars (FAO / FEWS NET Nigeria); the months are
 * the reproductive / yield-forming phase when a rainfall shortfall does the most damage. The
 * `stage` string is written plainly ("filling grain", "swelling tubers") — it goes straight
 * into a sentence on the region page.
 *
 * Idempotent (updateOrCreate on scope + scope_key + crop). It needs the crop_calendar table,
 * which the migration creates first.
 */
class CropCalendarSeeder extends Seeder
{
    public function run(): void
    {
        // zone => [crop, plain-language sensitive stage, [months that stage falls in]]
        $calendar = [
            AgroZone::SAHEL => [
                ['Millet', 'filling grain', [8, 9]],
                ['Sorghum', 'flowering', [9, 10]],
                ['Cowpea', 'filling pods', [9]],
            ],
            AgroZone::SUDAN_SAVANNA => [
                ['Millet', 'filling grain', [8, 9]],
                ['Sorghum', 'filling grain', [9, 10]],
                ['Maize', 'filling grain', [8, 9]],
                ['Cowpea', 'filling pods', [9, 10]],
                ['Groundnut', 'filling pods', [9]],
            ],
            AgroZone::NORTHERN_GUINEA => [
                ['Maize', 'filling grain', [8, 9]],
                ['Sorghum', 'filling grain', [9, 10]],
                ['Rice', 'flowering', [9, 10]],
                ['Soybean', 'filling pods', [9, 10]],
                ['Yam', 'swelling tubers', [8, 9, 10]],
            ],
            AgroZone::SOUTHERN_GUINEA => [
                ['Maize', 'filling grain', [6, 7]],
                ['Yam', 'swelling tubers', [8, 9, 10]],
                ['Rice', 'flowering', [9, 10]],
                ['Sorghum', 'filling grain', [9, 10]],
                ['Sesame', 'filling pods', [10, 11]],
            ],
            AgroZone::DERIVED_SAVANNA => [
                ['Maize', 'filling grain', [6, 7, 10]],
                ['Cassava', 'bulking roots', [7, 8, 9, 10]],
                ['Yam', 'swelling tubers', [8, 9, 10]],
                ['Cowpea', 'filling pods', [7, 10]],
                ['Rice', 'flowering', [9, 10]],
            ],
            AgroZone::HUMID_FOREST => [
                ['Maize', 'filling grain', [6, 7, 10, 11]],
                ['Cassava', 'bulking roots', [7, 8, 9, 10]],
                ['Yam', 'swelling tubers', [8, 9, 10, 11]],
                ['Rice', 'flowering', [9, 10]],
                ['Plantain', 'under dry-season water stress', [12, 1, 2]],
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
