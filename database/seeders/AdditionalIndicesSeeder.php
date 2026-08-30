<?php

namespace Database\Seeders;

use App\Models\IndexActionRecommendation;
use App\Models\RegionScoringConfig;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use Illuminate\Database\Seeder;

/**
 * Indices added after the original six (docs/BUILD_PLAN.md). Kept out of ReferenceDataSeeder
 * so production can pick them up — `php artisan db:seed --class=AdditionalIndicesSeeder` —
 * without re-running the calibration and scoring-weight seeding that would reset any
 * admin-tuned values.
 *
 * Fully idempotent: signal types are upserted; each index row is upserted; its scoring config,
 * calibration bounds and action text are created only when absent (firstOrCreate), so a re-run
 * never overwrites tuning done from the admin UI.
 *
 * Indices here may depend on signals beyond the original eight (Agriculture Stress needs
 * SOIL_MOISTURE and EVAPOTRANSPIRATION). Their ingestion services are registered in
 * config/ingestion.php and pull on the daily cadence / on region activation — the score simply
 * stays partial (renormalized over the signals present) until those readings land.
 *
 * Every index here ships UNCALIBRATED — the weights and bounds are transparent engineering
 * defaults, not validated against outcome data. Same honest caveat as the original six
 * (see docs/MODEL.md and docs/INGESTION_GUIDE.md).
 */
class AdditionalIndicesSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensureSignalTypes();
        $this->waterborneDiseaseRisk();
        $this->agricultureStress();
    }

    /**
     * Signal types this seeder's indices depend on that aren't in the original catalogue
     * (ReferenceDataSeeder::seedSignalTypes). Upserted here so a production run of just this
     * seeder is self-sufficient. signal_types carries no admin-tuned state, so updateOrCreate
     * is safe on re-run.
     */
    private function ensureSignalTypes(): void
    {
        $types = [
            // Drier soil is worse for crops — inverted, like elevation. Indices override direction as needed.
            ['code' => 'SOIL_MOISTURE', 'name' => 'Root-Zone Soil Moisture', 'unit' => 'm³/m³', 'source' => 'Open-Meteo Archive API (ERA5-Land, 7–28 cm)', 'higher_is_worse' => false],
            ['code' => 'EVAPOTRANSPIRATION', 'name' => 'Reference Evapotranspiration (ET₀)', 'unit' => 'mm', 'source' => 'Open-Meteo Archive API (FAO-56 Penman-Monteith)', 'higher_is_worse' => true],
        ];

        foreach ($types as $type) {
            SignalType::query()->updateOrCreate(['code' => $type['code']], $type);
        }
    }

    /**
     * Cholera and typhoid track contaminated standing water after flooding. Reuses the two
     * signals already ingested for Malaria / Flood Risk — no new ingestion. Attached to the
     * Public Health and Water & Sanitation sectors in SectorSeeder.
     */
    private function waterborneDiseaseRisk(): void
    {
        $index = ScoringIndex::query()->updateOrCreate(
            ['code' => 'WATERBORNE_DISEASE_RISK'],
            [
                'name' => 'Waterborne Disease Risk Index',
                'description' => 'Standing water + rainfall, weighted for cholera and typhoid risk after flooding — for WASH programmes and water-treatment targeting.',
            ]
        );

        $this->seedWeights($index, [
            'STANDING_WATER' => 0.5,
            'RAINFALL' => 0.5,
        ]);

        // Same climatologically-plausible ranges the original indices use for these signals
        // (see ReferenceDataSeeder::seedCalibrationParameters). Not epidemiologically calibrated.
        $this->seedBounds($index, [
            'RAINFALL' => [0, 200],
            'STANDING_WATER' => [0, 100],
        ]);

        $this->seedActions($index, [
            'green' => 'No action needed. Continue routine water-quality monitoring.',
            'amber' => 'Alert the WASH focal point. Pre-position water-treatment supplies (chlorine, ORS) and step up water-source surveillance in this LGA.',
            'red' => 'Activate the waterborne-disease response: treat and protect water sources in this LGA, pre-position ORS and IV fluids at health facilities, and issue a safe-water advisory. Notify the state epidemiologist within 48 hours.',
        ]);
    }

    /**
     * Soil-water focused, and deliberately distinct from Drought Risk (rainfall + NDVI): this
     * one leads with root-zone soil moisture and the crop water balance, a pre-visible signal
     * that moves weeks before vegetation stress shows up. Attached to the Agriculture sector
     * in SectorSeeder.
     */
    private function agricultureStress(): void
    {
        $index = ScoringIndex::query()->updateOrCreate(
            ['code' => 'AGRICULTURE_STRESS'],
            [
                'name' => 'Agriculture Stress Index',
                'description' => 'Root-zone soil moisture + rainfall deficit + evapotranspiration demand — an early, pre-visible signal of crop water stress for extension officers, ahead of what vegetation imagery would show.',
            ]
        );

        $this->seedWeights($index, [
            'SOIL_MOISTURE' => ['weight' => 0.5, 'higher_is_worse' => false], // drier root zone, more stress
            'RAINFALL' => ['weight' => 0.3, 'higher_is_worse' => false],      // rainfall deficit
            'EVAPOTRANSPIRATION' => 0.2,                                       // higher demand, more stress (signal default)
        ]);

        // ERA5-Land 7–28 cm volumetric water content typically runs ~0.05 (very dry) to ~0.40
        // (near saturation) in Nigeria. ET₀ is a weekly sum of daily mm. Neither is calibrated
        // against yield or crop-loss data.
        $this->seedBounds($index, [
            'SOIL_MOISTURE' => [0.05, 0.40],
            'RAINFALL' => [0, 200],
            'EVAPOTRANSPIRATION' => [0, 50],
        ]);

        $this->seedActions($index, [
            'green' => 'No action needed. Continue routine agronomic monitoring.',
            'amber' => 'Alert agricultural extension officers for this LGA. Begin water-conservation and supplementary-irrigation messaging to farmers, and prioritise field visits here.',
            'red' => 'Activate the crop water-stress response: prioritise irrigation support and drought-tolerant input distribution for this LGA, and brief the state agriculture ministry on the areas most at risk of yield loss this season.',
        ]);
    }

    /**
     * @param  array<string, float|array{weight: float, higher_is_worse: ?bool}>  $weights
     */
    private function seedWeights(ScoringIndex $index, array $weights): void
    {
        foreach ($weights as $signalCode => $config) {
            $signalType = SignalType::query()->where('code', $signalCode)->firstOrFail();
            $weight = is_array($config) ? $config['weight'] : $config;
            $higherIsWorse = is_array($config) ? ($config['higher_is_worse'] ?? null) : null;

            RegionScoringConfig::query()->firstOrCreate(
                [
                    'index_id' => $index->index_id,
                    'region_id' => null,
                    'signal_type_id' => $signalType->signal_type_id,
                ],
                ['weight' => $weight, 'higher_is_worse' => $higherIsWorse, 'enabled' => true]
            );
        }
    }

    /**
     * @param  array<string, array{0: int|float, 1: int|float}>  $bounds
     */
    private function seedBounds(ScoringIndex $index, array $bounds): void
    {
        foreach ($bounds as $signalCode => [$min, $max]) {
            foreach (['MIN' => $min, 'MAX' => $max] as $suffix => $value) {
                ScoringCalibrationParameter::query()->firstOrCreate(
                    ['index_id' => $index->index_id, 'region_id' => null, 'parameter_key' => "{$signalCode}_{$suffix}"],
                    ['parameter_value' => $value, 'source_reference' => 'Uncalibrated placeholder — tune once historical case data is available.']
                );
            }
        }
    }

    /**
     * @param  array<string, string>  $bands
     */
    private function seedActions(ScoringIndex $index, array $bands): void
    {
        foreach ($bands as $band => $text) {
            IndexActionRecommendation::query()->firstOrCreate(
                ['index_id' => $index->index_id, 'risk_band' => $band],
                ['action_text' => $text]
            );
        }
    }
}
