<?php

namespace Database\Seeders;

use App\Models\ScoringIndex;
use App\Models\Sector;
use Illuminate\Database\Seeder;

/**
 * Grouping labels over the indices — a user picks the sector(s) matching their job and it
 * expands to the underlying index subscriptions. An index can sit in more than one sector
 * (Flood Risk is relevant to both Water and Emergency Response). New roadmap indices
 * (docs/BUILD_PLAN.md) attach here as they ship — this seed only maps what's live today.
 *
 * Kept separate from ReferenceDataSeeder so production can run `db:seed --class=SectorSeeder`
 * to add the sectors without also re-running calibration/scoring-weight seeding (which would
 * reset any admin-tuned values). Fully idempotent — safe to re-run.
 */
class SectorSeeder extends Seeder
{
    public function run(): void
    {
        $sectors = [
            [
                'code' => 'OVERVIEW',
                'name' => 'Overview',
                'description' => 'The at-a-glance cross-cutting snapshot. Pre-selected for everyone.',
                'is_default' => true,
                'indices' => ['COMPOSITE_PRESSURE'],
            ],
            [
                'code' => 'PUBLIC_HEALTH',
                'name' => 'Public Health & Epidemiology',
                'description' => 'Climate-linked disease risk — malaria, waterborne disease, respiratory illness, heat-health.',
                'indices' => ['MALARIA_RISK', 'WATERBORNE_DISEASE_RISK', 'RESPIRATORY_RISK', 'HEAT_STRESS_RISK'],
            ],
            [
                'code' => 'AGRICULTURE',
                'name' => 'Agriculture & Food Security',
                'description' => 'Rainfall deficit, soil-water stress and vegetation stress ahead of a bad season.',
                'indices' => ['DROUGHT_RISK', 'AGRICULTURE_STRESS', 'IRRIGATION_NEED', 'RANGELAND_STRESS'],
            ],
            [
                'code' => 'EMERGENCY_RESPONSE',
                'name' => 'Emergency Response & Infrastructure',
                'description' => 'Forward signal on which areas are closest to flooding, dangerous heat, wildfire or dust storms.',
                'indices' => ['FLOOD_RISK', 'RIVERINE_FLOOD_FORECAST', 'HEAT_STRESS_RISK', 'WILDFIRE_RISK', 'DUST_STORM_RISK'],
            ],
            [
                'code' => 'WATER_SANITATION',
                'name' => 'Water, Sanitation & Flooding',
                'description' => 'Flood risk, post-flood waterborne-disease risk and dry-season water availability for WASH and water-supply planning.',
                'indices' => ['FLOOD_RISK', 'WATERBORNE_DISEASE_RISK', 'DRY_SEASON_WATER_STRESS', 'RIVERINE_FLOOD_FORECAST'],
            ],
            [
                'code' => 'AIR_ENVIRONMENT',
                'name' => 'Environment & Air Quality',
                'description' => 'Particulate pollution and harmattan dust, refreshed daily.',
                'indices' => ['RESPIRATORY_RISK', 'DUST_STORM_RISK'],
            ],
        ];

        foreach ($sectors as $order => $definition) {
            $indexCodes = $definition['indices'];
            unset($definition['indices']);

            $sector = Sector::query()->updateOrCreate(
                ['code' => $definition['code']],
                [...$definition, 'sort_order' => $order]
            );

            $pivot = [];
            foreach (array_values($indexCodes) as $indexOrder => $indexCode) {
                $index = ScoringIndex::query()->where('code', $indexCode)->first();
                if ($index) {
                    $pivot[$index->index_id] = ['sort_order' => $indexOrder];
                }
            }

            $sector->indices()->syncWithoutDetaching($pivot);
        }
    }
}
