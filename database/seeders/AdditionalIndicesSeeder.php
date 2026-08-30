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
 * Fully idempotent: each index row is upserted; its scoring config, calibration bounds and
 * action text are created only when absent (firstOrCreate), so a re-run never overwrites
 * tuning done from the admin UI.
 *
 * Every index here ships UNCALIBRATED — the weights and bounds are transparent engineering
 * defaults, not validated against outcome data. Same honest caveat as the original six
 * (see docs/MODEL.md and docs/INGESTION_GUIDE.md).
 */
class AdditionalIndicesSeeder extends Seeder
{
    public function run(): void
    {
        $this->waterborneDiseaseRisk();
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
     * @param  array<string, float>  $weights
     */
    private function seedWeights(ScoringIndex $index, array $weights): void
    {
        foreach ($weights as $signalCode => $weight) {
            $signalType = SignalType::query()->where('code', $signalCode)->firstOrFail();

            RegionScoringConfig::query()->firstOrCreate(
                [
                    'index_id' => $index->index_id,
                    'region_id' => null,
                    'signal_type_id' => $signalType->signal_type_id,
                ],
                ['weight' => $weight, 'higher_is_worse' => null, 'enabled' => true]
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
