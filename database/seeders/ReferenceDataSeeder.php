<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\IndexActionRecommendation;
use App\Models\Region;
use App\Models\RegionScoringConfig;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSignalTypes();
        $this->seedIndices();
        $this->seedActionRecommendations();
        $this->seedRegions();
        $this->seedCalibrationParameters();
        $this->seedScoringConfigs();
        $this->seedAgencies();
    }

    private function seedSignalTypes(): void
    {
        $types = [
            ['code' => 'RAINFALL', 'name' => 'Rainfall', 'unit' => 'mm', 'source' => 'NASA POWER / CHIRPS', 'higher_is_worse' => true],
            ['code' => 'STANDING_WATER', 'name' => 'Standing Water', 'unit' => '%', 'source' => 'JRC Global Surface Water / Sentinel-2 NDWI', 'higher_is_worse' => true],
            ['code' => 'TEMPERATURE', 'name' => 'Temperature', 'unit' => '°C', 'source' => 'NASA POWER / ERA5', 'higher_is_worse' => true],
            ['code' => 'VEGETATION', 'name' => 'Vegetation / Humidity', 'unit' => 'NDVI', 'source' => 'MODIS', 'higher_is_worse' => true],
            ['code' => 'POPULATION_EXPOSURE', 'name' => 'Population Exposure', 'unit' => 'people', 'source' => 'WorldPop / GRID3 Nigeria', 'higher_is_worse' => true],
            // Lower ground floods first — the only inverted signal in the catalogue.
            ['code' => 'ELEVATION', 'name' => 'Elevation / Terrain', 'unit' => 'm', 'source' => 'SRTM', 'higher_is_worse' => false],
        ];

        foreach ($types as $type) {
            SignalType::query()->updateOrCreate(['code' => $type['code']], $type);
        }
    }

    private function seedIndices(): void
    {
        $indices = [
            [
                'code' => 'MALARIA_RISK',
                'name' => 'Malaria Risk Index',
                'description' => 'Rainfall + standing water, weighted for malaria programme officers.',
            ],
            [
                'code' => 'FLOOD_RISK',
                'name' => 'Flood Risk Index',
                'description' => 'Rainfall + standing water + elevation/terrain, for emergency response.',
            ],
            [
                'code' => 'COMPOSITE_PRESSURE',
                'name' => 'Composite Climate-Health Pressure Index',
                'description' => 'All active signals, weighted, for an overall regional snapshot.',
            ],
            [
                'code' => 'HEAT_STRESS_RISK',
                'name' => 'Heat Stress Risk Index',
                'description' => 'Temperature + vegetation loss, for occupational and public heat-health planning.',
            ],
            [
                'code' => 'DROUGHT_RISK',
                'name' => 'Drought Risk Index',
                'description' => 'Rainfall deficit + vegetation stress, for agricultural and water-security planning.',
            ],
        ];

        foreach ($indices as $index) {
            ScoringIndex::query()->updateOrCreate(['code' => $index['code']], $index);
        }
    }

    /**
     * Rule-based, not AI — a lookup table an officer can act on immediately.
     * Same 34/67 bands as App\Support\RiskBand, applied per index.
     */
    private function seedActionRecommendations(): void
    {
        $actions = [
            'MALARIA_RISK' => [
                'green' => 'No action needed. Continue routine surveillance.',
                'amber' => 'Increase vector surveillance and pre-position rapid diagnostic tests (RDTs) at nearby health facilities.',
                'red' => 'Distribute RDTs and pre-position ACTs (artemisinin-based combination therapies) in this LGA. Notify the state malaria programme within 48 hours.',
            ],
            'FLOOD_RISK' => [
                'green' => 'No action needed. Continue routine monitoring.',
                'amber' => 'Alert the LGA emergency management focal point. Review evacuation routes and shelter capacity.',
                'red' => 'Activate the flood emergency response plan. Pre-position emergency shelter and clean water supplies, and issue a public evacuation advisory.',
            ],
            'COMPOSITE_PRESSURE' => [
                'green' => 'No cross-cutting concern. Continue routine monitoring across all indices.',
                'amber' => 'Review the index breakdown for this region — one or more signals are elevated. Brief the LGA health officer.',
                'red' => 'Convene a multi-sectoral response review (health, water, emergency management) for this LGA within the week.',
            ],
            'HEAT_STRESS_RISK' => [
                'green' => 'No action needed.',
                'amber' => 'Issue a heat advisory to outdoor workers and schools. Confirm health facilities are stocked for heat-related illness.',
                'red' => 'Issue a public heat warning. Open cooling centres where available and suspend non-essential outdoor activity during peak hours.',
            ],
            'DROUGHT_RISK' => [
                'green' => 'No action needed. Continue routine monitoring.',
                'amber' => 'Alert agricultural extension officers and water resource managers. Begin water-conservation messaging.',
                'red' => 'Activate the drought contingency plan: prioritise water rationing for vulnerable communities and notify the state agriculture/water ministry.',
            ],
        ];

        foreach ($actions as $indexCode => $bands) {
            $index = ScoringIndex::query()->where('code', $indexCode)->first();

            if (! $index) {
                continue;
            }

            foreach ($bands as $band => $text) {
                IndexActionRecommendation::query()->updateOrCreate(
                    ['index_id' => $index->index_id, 'risk_band' => $band],
                    ['action_text' => $text]
                );
            }
        }
    }

    private function seedRegions(): void
    {
        // 2006 census (NPC) population figures, rounded. Coordinates are LGA-seat
        // centroids, not administrative boundary centroids — accurate enough for
        // point-based signal ingestion, not for polygon geometry.
        $regions = [
            ['name' => 'Ikeja', 'state' => 'Lagos', 'lga_code' => 'NG-LA-IKJ', 'latitude' => 6.6018, 'longitude' => 3.3515, 'population' => 313196],
            ['name' => 'Yenagoa', 'state' => 'Bayelsa', 'lga_code' => 'NG-BY-YNG', 'latitude' => 4.9247, 'longitude' => 6.2642, 'population' => 352285],
            ['name' => 'Ibadan North', 'state' => 'Oyo', 'lga_code' => 'NG-OY-IBN', 'latitude' => 7.3964, 'longitude' => 3.9167, 'population' => 308119],
            ['name' => 'Kano Municipal', 'state' => 'Kano', 'lga_code' => 'NG-KN-KNM', 'latitude' => 12.0022, 'longitude' => 8.5920, 'population' => 365525],
            ['name' => 'Port Harcourt', 'state' => 'Rivers', 'lga_code' => 'NG-RV-PHC', 'latitude' => 4.8156, 'longitude' => 7.0498, 'population' => 541115],
            ['name' => 'Maiduguri', 'state' => 'Borno', 'lga_code' => 'NG-BO-MDG', 'latitude' => 11.8333, 'longitude' => 13.1500, 'population' => 540016],
            ['name' => 'Sokoto North', 'state' => 'Sokoto', 'lga_code' => 'NG-SK-SKN', 'latitude' => 13.0627, 'longitude' => 5.2433, 'population' => 227847],
            ['name' => 'Abuja Municipal', 'state' => 'FCT', 'lga_code' => 'NG-FC-AMC', 'latitude' => 9.0765, 'longitude' => 7.3986, 'population' => 778567],
        ];

        foreach ($regions as $region) {
            Region::query()->updateOrCreate(['lga_code' => $region['lga_code']], $region);
        }
    }

    /**
     * System-wide (region_id = null) normalization bounds, one pair per signal per index.
     * Uncalibrated placeholders — the brief asks these be tunable without code changes once
     * real historical data is available, which is exactly what this table is for.
     */
    private function seedCalibrationParameters(): void
    {
        $bounds = [
            'RAINFALL' => ['min' => 0, 'max' => 200],
            'STANDING_WATER' => ['min' => 0, 'max' => 100],
            'TEMPERATURE' => ['min' => 15, 'max' => 45],
            'VEGETATION' => ['min' => -1, 'max' => 1],
            'POPULATION_EXPOSURE' => ['min' => 0, 'max' => 1000000],
            'ELEVATION' => ['min' => 0, 'max' => 500],
        ];

        foreach (ScoringIndex::all() as $index) {
            foreach ($bounds as $signalCode => $range) {
                foreach (['MIN' => $range['min'], 'MAX' => $range['max']] as $suffix => $value) {
                    ScoringCalibrationParameter::query()->updateOrCreate(
                        ['index_id' => $index->index_id, 'region_id' => null, 'parameter_key' => "{$signalCode}_{$suffix}"],
                        ['parameter_value' => $value, 'source_reference' => 'Uncalibrated placeholder — tune once historical case data is available.']
                    );
                }
            }
        }
    }

    /**
     * System-wide (region_id = null) signal weightings per named index. A region can override
     * any of these by inserting its own row with the same index_id/signal_type_id and a
     * non-null region_id — no code change required.
     *
     * Each signal entry is either a bare weight (use the signal's default direction from
     * signal_types.higher_is_worse) or ['weight' => ..., 'higher_is_worse' => ...] to override
     * direction for this index specifically — e.g. rainfall is bad-when-high for Flood/Malaria
     * but bad-when-low for Drought.
     */
    private function seedScoringConfigs(): void
    {
        $weights = [
            'MALARIA_RISK' => ['RAINFALL' => 0.5, 'STANDING_WATER' => 0.5],
            'FLOOD_RISK' => ['RAINFALL' => 0.4, 'STANDING_WATER' => 0.4, 'ELEVATION' => 0.2],
            'COMPOSITE_PRESSURE' => [
                'RAINFALL' => 0.25,
                'STANDING_WATER' => 0.25,
                'TEMPERATURE' => 0.2,
                'VEGETATION' => 0.15,
                'POPULATION_EXPOSURE' => 0.15,
            ],
            'HEAT_STRESS_RISK' => [
                'TEMPERATURE' => 0.7,
                'VEGETATION' => ['weight' => 0.3, 'higher_is_worse' => false], // less vegetation, less shade/cooling
            ],
            'DROUGHT_RISK' => [
                'RAINFALL' => ['weight' => 0.5, 'higher_is_worse' => false], // less rain, more drought
                'VEGETATION' => ['weight' => 0.5, 'higher_is_worse' => false], // lower NDVI, more vegetation stress
            ],
        ];

        foreach ($weights as $indexCode => $signalWeights) {
            $index = ScoringIndex::query()->where('code', $indexCode)->firstOrFail();

            foreach ($signalWeights as $signalCode => $config) {
                $signalType = SignalType::query()->where('code', $signalCode)->firstOrFail();
                $weight = is_array($config) ? $config['weight'] : $config;
                $higherIsWorse = is_array($config) ? $config['higher_is_worse'] : null;

                RegionScoringConfig::query()->updateOrCreate(
                    ['index_id' => $index->index_id, 'region_id' => null, 'signal_type_id' => $signalType->signal_type_id],
                    ['weight' => $weight, 'higher_is_worse' => $higherIsWorse, 'enabled' => true]
                );
            }
        }
    }

    private function seedAgencies(): void
    {
        $agencies = [
            ['name' => 'Lagos State Ministry of Health', 'type' => 'State Ministry of Health'],
            ['name' => 'Bayelsa State Emergency Management Agency', 'type' => 'Emergency Response'],
            ['name' => 'Nigeria Centre for Disease Control (NCDC)', 'type' => 'Federal Public Health Agency'],
        ];

        foreach ($agencies as $agency) {
            Agency::query()->firstOrCreate(['name' => $agency['name']], $agency);
        }
    }
}
